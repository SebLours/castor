<?php

namespace Castor\Stub;

use Castor\Console\Application;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Finder\Finder;

/** @internal */
final class StubsGenerator
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function generateStubsIfNeeded(string $dest): void
    {
        if ($this->shouldGenerate($dest)) {
            $this->logger->debug('Generating stubs...');
            $this->generateStubs($dest);
        }
    }

    public function generateStubs(string $dest): void
    {
        if (!is_writable(\dirname($dest))) {
            $this->logger->warning("Could not generate stubs as the destination \"{$dest}\" is not writeable.");

            return;
        }

        $basePath = \dirname(__DIR__, 2);
        $finder = new Finder();

        $finder
            ->files()
            ->in("{$basePath}/src")
            ->name('*.php')
            ->sortByName()
        ;

        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $stmts = [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NodeVisitor());

        foreach ($finder as $file) {
            $fileStmts = $parser->parse((string) file_get_contents($file->getPathname()));
            if (!$fileStmts) {
                continue;
            }
            $stmts = array_merge($stmts, $traverser->traverse($fileStmts));
        }

        // Add some very frequently used classes
        $frequentlyUsedClasses = [
            \Symfony\Component\Console\Application::class,
            \Symfony\Component\Console\Input\InputArgument::class,
            \Symfony\Component\Console\Input\InputInterface::class,
            \Symfony\Component\Console\Input\InputOption::class,
            \Symfony\Component\Console\Output\OutputInterface::class,
            \Symfony\Component\Console\Style\SymfonyStyle::class,
            \Symfony\Component\Filesystem\Exception\ExceptionInterface::class,
            \Symfony\Component\Filesystem\Filesystem::class,
            \Symfony\Component\Filesystem\Path::class,
            Finder::class,
            \Symfony\Component\Process\Exception\ExceptionInterface::class,
            \Symfony\Component\Process\ExecutableFinder::class,
            \Symfony\Component\Process\Process::class,
        ];

        foreach ($frequentlyUsedClasses as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            if (!$file) {
                continue;
            }
            $fileStmts = $parser->parse((string) file_get_contents($file));
            if (!$fileStmts) {
                continue;
            }
            $stmts = array_merge($stmts, $traverser->traverse($fileStmts));
        }

        array_unshift($stmts, new \PhpParser\Node\Stmt\Nop([
            'comments' => [
                new \PhpParser\Comment\Doc(sprintf('// castor version: %s', Application::VERSION)),
            ],
        ]));

        $code = (new Standard())->prettyPrintFile($stmts);

        file_put_contents($dest, $code);
    }

    private function shouldGenerate(string $dest): bool
    {
        // Do not generate stubs when working on castor
        if (($cwd = getcwd()) && str_starts_with(\dirname(__DIR__, 2), $cwd)) {
            return false;
        }

        if (!file_exists($dest)) {
            return true;
        }

        $content = (string) file_get_contents($dest);
        preg_match('{^// castor version: (.+)$}m', $content, $matches);
        if (!$matches) {
            return true;
        }

        return Application::VERSION !== $matches[1];
    }
}
