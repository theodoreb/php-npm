<?php

declare(strict_types=1);

namespace PhpNpm\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use PhpNpm\Arborist\Arborist;
use PhpNpm\Dependency\Node;

/**
 * List installed packages (php-npm ls).
 */
class ListCommand extends Command
{
    protected static $defaultName = 'list';
    protected static $defaultDescription = 'List installed packages';

    protected function configure(): void
    {
        $this
            ->setName('list')
            ->setAliases(['ls', 'la', 'll'])
            ->setDescription('List installed packages')
            ->addOption(
                'depth',
                null,
                InputOption::VALUE_REQUIRED,
                'Max depth to display',
                '1'
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Show all packages (no depth limit)'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output as JSON'
            )
            ->addOption(
                'production',
                'p',
                InputOption::VALUE_NONE,
                'Show only production dependencies'
            )
            ->addOption(
                'dev',
                'd',
                InputOption::VALUE_NONE,
                'Show only dev dependencies'
            )
            ->setHelp(<<<'HELP'
The <info>list</info> command shows installed packages.

List direct dependencies:
    <info>php-npm ls</info>

List all packages:
    <info>php-npm ls --all</info>

List to specific depth:
    <info>php-npm ls --depth=2</info>

Output as JSON:
    <info>php-npm ls --json</info>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = getcwd();

        $maxDepth = $input->getOption('all') ? PHP_INT_MAX : (int) $input->getOption('depth');
        $jsonOutput = $input->getOption('json');
        $prodOnly = $input->getOption('production');
        $devOnly = $input->getOption('dev');

        try {
            $arborist = new Arborist($path);
            $tree = $arborist->loadActual();

            if ($jsonOutput) {
                $data = $this->treeToJson($tree, $maxDepth, $prodOnly, $devOnly);
                $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->printTree($io, $tree, $maxDepth, $prodOnly, $devOnly);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error($e->getMessage());

            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Print tree to console.
     */
    private function printTree(
        SymfonyStyle $io,
        Node $node,
        int $maxDepth,
        bool $prodOnly,
        bool $devOnly,
        int $depth = 0,
        string $prefix = ''
    ): void {
        if ($depth > $maxDepth) {
            return;
        }

        // Print current node
        if ($node->isRoot()) {
            $io->writeln(sprintf('%s@%s', $node->getName() ?: basename(getcwd()), $node->getVersion()));
        } else {
            $flags = [];
            if ($node->isDev()) {
                $flags[] = 'dev';
            }
            if ($node->isOptional()) {
                $flags[] = 'optional';
            }
            if ($node->isPeer()) {
                $flags[] = 'peer';
            }
            if ($node->isExtraneous()) {
                $flags[] = 'extraneous';
            }

            $flagStr = empty($flags) ? '' : ' (' . implode(', ', $flags) . ')';
            $io->writeln(sprintf('%s%s@%s%s', $prefix, $node->getName(), $node->getVersion(), $flagStr));
        }

        // Get children
        $children = $node->getChildren();

        if (empty($children) || $depth >= $maxDepth) {
            return;
        }

        // Filter children
        if ($prodOnly) {
            $children = array_filter($children, fn(Node $n) => !$n->isDev());
        } elseif ($devOnly) {
            $children = array_filter($children, fn(Node $n) => $n->isDev());
        }

        // Sort by name
        ksort($children);

        $childCount = count($children);
        $index = 0;

        foreach ($children as $child) {
            $index++;
            $isLast = $index === $childCount;

            $childPrefix = $node->isRoot() ? '' : $prefix;
            $connector = $isLast ? '└── ' : '├── ';
            $nextPrefix = $childPrefix . ($isLast ? '    ' : '│   ');

            $this->printTree(
                $io,
                $child,
                $maxDepth,
                $prodOnly,
                $devOnly,
                $depth + 1,
                $childPrefix . $connector
            );
        }
    }

    /**
     * Convert tree to JSON structure.
     */
    private function treeToJson(
        Node $node,
        int $maxDepth,
        bool $prodOnly,
        bool $devOnly,
        int $depth = 0
    ): array {
        $data = [
            'name' => $node->getName(),
            'version' => $node->getVersion(),
        ];

        if (!$node->isRoot()) {
            if ($node->isDev()) {
                $data['dev'] = true;
            }
            if ($node->isOptional()) {
                $data['optional'] = true;
            }
            if ($node->isPeer()) {
                $data['peer'] = true;
            }
            if ($node->isExtraneous()) {
                $data['extraneous'] = true;
            }
            if ($node->getResolved()) {
                $data['resolved'] = $node->getResolved();
            }
        }

        $children = $node->getChildren();

        if (!empty($children) && $depth < $maxDepth) {
            // Filter
            if ($prodOnly) {
                $children = array_filter($children, fn(Node $n) => !$n->isDev());
            } elseif ($devOnly) {
                $children = array_filter($children, fn(Node $n) => $n->isDev());
            }

            if (!empty($children)) {
                $data['dependencies'] = [];
                foreach ($children as $name => $child) {
                    $data['dependencies'][$name] = $this->treeToJson(
                        $child,
                        $maxDepth,
                        $prodOnly,
                        $devOnly,
                        $depth + 1
                    );
                }
            }
        }

        return $data;
    }
}
