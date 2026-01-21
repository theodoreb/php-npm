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
    protected static $defaultDescription = 'Clean install from package-lock.json';

    protected function configure(): void
    {
        $this
            ->setName('ci')
            ->setAliases(['clean-install'])
            ->setDescription('Clean install from package-lock.json')
            ->addOption(
                'registry',
                null,
                InputOption::VALUE_REQUIRED,
                'Registry URL to use'
            )
            ->setHelp(<<<'HELP'
The <info>ci</info> command performs a clean install from package-lock.json.

This command is similar to <info>install</info>, but:
- Removes existing node_modules before installing
- Requires package-lock.json to exist
- Never writes to package.json or package-lock.json
- Fails if package-lock.json is outdated

This is intended for use in automated environments (CI/CD):
    <info>php-npm ci</info>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = getcwd();

        // Check for lockfile
        if (!file_exists($path . '/package-lock.json') && !file_exists($path . '/npm-shrinkwrap.json')) {
            $io->error('This command requires a package-lock.json or npm-shrinkwrap.json file.');
            $io->text('Run "php-npm install" first to generate one.');
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
