<?php

declare(strict_types=1);

namespace PhpNpm\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use PhpNpm\Arborist\Arborist;

/**
 * Clean install from lockfile (php-npm ci).
 */
class CiCommand extends Command
{
    protected static $defaultName = 'ci';
    protected static $defaultDescription = 'Clean install from lockfile (package-lock.json or yarn.lock)';

    protected function configure(): void
    {
        $this
            ->setName('ci')
            ->setAliases(['clean-install'])
            ->setDescription('Clean install from lockfile (package-lock.json or yarn.lock)')
            ->addOption(
                'registry',
                null,
                InputOption::VALUE_REQUIRED,
                'Registry URL to use'
            )
            ->setHelp(<<<'HELP'
The <info>ci</info> command performs a clean install from a lockfile.

Supported lockfiles (in priority order):
- npm-shrinkwrap.json
- package-lock.json
- yarn.lock (Yarn Berry v2+ format)

This command is similar to <info>install</info>, but:
- Removes existing node_modules before installing
- Requires a lockfile to exist
- Never writes to package.json or the lockfile
- Fails if the lockfile is outdated

This is intended for use in automated environments (CI/CD):
    <info>php-npm ci</info>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = getcwd();

        // Check for lockfile (npm-shrinkwrap.json, package-lock.json, or yarn.lock)
        $hasLockfile = file_exists($path . '/npm-shrinkwrap.json')
            || file_exists($path . '/package-lock.json')
            || file_exists($path . '/yarn.lock');

        if (!$hasLockfile) {
            $io->error('This command requires a lockfile (package-lock.json, npm-shrinkwrap.json, or yarn.lock).');
            $io->text('Run "php-npm install" first to generate one, or use an existing yarn.lock.');
            return Command::FAILURE;
        }

        $options = [
            'registry' => $input->getOption('registry'),
        ];

        try {
            $arborist = new Arborist($path, $options);

            // Set up progress reporting
            $arborist->onProgress(function (string $message, int $current, int $total) use ($io) {
                if ($total > 0) {
                    $io->text(sprintf('[%d/%d] %s', $current + 1, $total, $message));
                } else {
                    $io->text($message);
                }
            });

            $io->section('Clean install');
            $arborist->ci($options);
            $io->success('Clean install completed successfully');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error($e->getMessage());

            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}
