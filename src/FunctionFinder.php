<?php

namespace Castor;

use Castor\Attribute\AsContext;
use Castor\Attribute\AsContextGenerator;
use Castor\Attribute\AsListener;
use Castor\Attribute\AsSymfonyTask;
use Castor\Attribute\AsTask;
use Castor\Exception\FunctionConfigurationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** @internal */
class FunctionFinder
{
    /** @var array<string> */
    public static array $files = [];

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $rootDir,
    ) {
    }

    /** @return iterable<TaskDescriptor|ContextDescriptor|ContextGeneratorDescriptor|ListenerDescriptor|SymfonyTaskDescriptor> */
    public function findFunctions(string $path): iterable
    {
        yield from self::doFindFunctions([new \SplFileInfo($path . '/castor.php')]);

        $castorDirectory = $path . '/castor';

        if (is_dir($castorDirectory)) {
            $files = Finder::create()
                ->files()
                ->name('*.php')
                ->in($castorDirectory)
            ;

            yield from self::doFindFunctions($files);
        }
    }

    /**
     * @param iterable<\SplFileInfo> $files
     *
     * @return iterable<TaskDescriptor|ContextDescriptor|ContextGeneratorDescriptor|ListenerDescriptor|SymfonyTaskDescriptor>
     */
    private function doFindFunctions(iterable $files): iterable
    {
        $initialFunctions = get_defined_functions()['user'];
        $initialClasses = get_declared_classes();

        foreach ($files as $file) {
            castor_require($file->getPathname());
        }

        $newFunctions = array_diff(get_defined_functions()['user'], $initialFunctions);
        foreach ($newFunctions as $functionName) {
            $reflectionFunction = new \ReflectionFunction($functionName);

            yield from $this->resolveTasks($reflectionFunction);
            yield from $this->resolveContexts($reflectionFunction);
            yield from $this->resolveContextGenerators($reflectionFunction);
            yield from $this->resolveListeners($reflectionFunction);
        }

        // Symfony task in repacked application are not supported
        if (class_exists(\RepackedApplication::class)) {
            return;
        }

        $newClasses = array_diff(get_declared_classes(), $initialClasses);
        foreach ($newClasses as $className) {
            $reflectionClass = new \ReflectionClass($className);

            yield from $this->resolveSymfonyTask($reflectionClass);
        }
    }

    /**
     * @return iterable<TaskDescriptor>
     */
    private function resolveTasks(\ReflectionFunction $reflectionFunction): iterable
    {
        $attributes = $reflectionFunction->getAttributes(AsTask::class, \ReflectionAttribute::IS_INSTANCEOF);
        if (!\count($attributes)) {
            return;
        }

        try {
            $taskAttribute = $attributes[0]->newInstance();
        } catch (\Throwable $e) {
            throw new FunctionConfigurationException(sprintf('Could not instantiate the attribute "%s".', AsTask::class), $reflectionFunction, $e);
        }

        if ('' === $taskAttribute->name) {
            $taskAttribute->name = SluggerHelper::slug($reflectionFunction->getShortName());
        }

        if (null === $taskAttribute->namespace) {
            $ns = str_replace('/', ':', \dirname(str_replace('\\', '/', $reflectionFunction->getName())));
            $ns = implode(':', array_map(SluggerHelper::slug(...), explode(':', $ns)));
            $taskAttribute->namespace = $ns;
        }

        foreach ($taskAttribute->onSignals as $signal => $callable) {
            if (!\is_callable($callable)) {
                throw new FunctionConfigurationException(sprintf('The callable for signal "%s" is not callable.', $signal), $reflectionFunction);
            }
        }

        yield new TaskDescriptor($taskAttribute, $reflectionFunction);
    }

    /**
     * @return iterable<SymfonyTaskDescriptor>
     */
    private function resolveSymfonyTask(\ReflectionClass $reflectionClass): iterable
    {
        $attributes = $reflectionClass->getAttributes(AsSymfonyTask::class, \ReflectionAttribute::IS_INSTANCEOF);
        if (!\count($attributes)) {
            return;
        }

        $taskAttribute = $attributes[0]->newInstance();

        $console = $taskAttribute->console;

        $key = hash('sha256', implode('-', ['symfony-console-definitions-', ...$console, $this->rootDir]));

        $definitions = $this->cache->get($key, function (ItemInterface $item) use ($console) {
            $item->expiresAfter(60 * 60 * 24);
            $p = new Process([...$console, '--format=json']);
            $p->mustRun();

            return json_decode($p->getOutput(), true);
        });

        $sfAttribute = $reflectionClass->getAttributes(AsCommand::class);
        if (\count($sfAttribute)) {
            $sfAttribute = $sfAttribute[0]->newInstance();
            if (!$taskAttribute->originalName) {
                $taskAttribute->originalName = $sfAttribute->name;
            }
            if (!$taskAttribute->name) {
                $taskAttribute->name = $sfAttribute->name;
            }
        }

        if (!$taskAttribute->name) {
            throw new FunctionConfigurationException('The task command must have a name.', $reflectionClass);
        }

        $definition = null;
        foreach ($definitions['commands'] as $definition) {
            if ($definition['name'] !== $taskAttribute->originalName) {
                continue;
            }

            break;
        }
        if ($definition['name'] !== $taskAttribute->originalName) {
            throw new FunctionConfigurationException(sprintf('Could not find a command named "%s" in the Symfony application', $taskAttribute->name), $reflectionClass);
        }

        yield new SymfonyTaskDescriptor($taskAttribute, $reflectionClass, $definition);
    }

    /**
     * @return iterable<ContextDescriptor>
     */
    private function resolveContexts(\ReflectionFunction $reflectionFunction): iterable
    {
        $attributes = $reflectionFunction->getAttributes(AsContext::class, \ReflectionAttribute::IS_INSTANCEOF);
        if (!\count($attributes)) {
            return;
        }

        $contextAttribute = $attributes[0]->newInstance();

        if ('' === $contextAttribute->name) {
            if ($contextAttribute->default) {
                $contextAttribute->name = 'default';
            } else {
                $contextAttribute->name = SluggerHelper::slug($reflectionFunction->getShortName());
            }
        }

        yield new ContextDescriptor($contextAttribute, $reflectionFunction);
    }

    /**
     * @return iterable<ContextGeneratorDescriptor>
     */
    private function resolveContextGenerators(\ReflectionFunction $reflectionFunction): iterable
    {
        $attributes = $reflectionFunction->getAttributes(AsContextGenerator::class, \ReflectionAttribute::IS_INSTANCEOF);
        if (!\count($attributes)) {
            return;
        }

        $contextAttribute = $attributes[0]->newInstance();

        if ($reflectionFunction->getParameters()) {
            throw new FunctionConfigurationException('The contexts generator must not have arguments.', $reflectionFunction);
        }
        $generators = [];
        foreach ($reflectionFunction->invoke() as $name => $generator) {
            if (!$generator instanceof \Closure) {
                throw new FunctionConfigurationException(sprintf('The context generator "%s" is not callable.', $name), $reflectionFunction);
            }
            $r = new \ReflectionFunction($generator);
            if ($r->getParameters()) {
                throw new FunctionConfigurationException(sprintf('The context generator "%s" must not have arguments.', $name), $reflectionFunction);
            }
            $generators[$name] = $generator;
        }

        yield new ContextGeneratorDescriptor($contextAttribute, $reflectionFunction, $generators);
    }

    /**
     * @return iterable<ListenerDescriptor>
     */
    private function resolveListeners(\ReflectionFunction $reflectionFunction): iterable
    {
        $attributes = $reflectionFunction->getAttributes(AsListener::class);

        foreach ($attributes as $attribute) {
            /** @var AsListener $listenerAttribute */
            $listenerAttribute = $attribute->newInstance();

            yield new ListenerDescriptor($listenerAttribute, $reflectionFunction);
        }
    }
}

// Don't leak internal variables
/** @internal */
function castor_require(string $file): void
{
    if (!is_file($file)) {
        throw new \RuntimeException(sprintf('Could not find file "%s".', $file));
    }

    FunctionFinder::$files[] = $file;

    require_once $file;
}
