<?php

declare(strict_types=1);

namespace PhpNpm\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use PhpNpm\Arborist\Arborist;

/**
 * Install dependencies (php-npm install).
 */
class InstallCommand extends Command
{
    protected static $defaultName = 'install';
    protected static $defaultDescription = 'Install dependencies from package.json';

    protected function configure(): void
    {
        $this
            ->setName('install')
            ->setAliases(['i', 'add'])
            ->setDescription('Install dependencies from package.json')
            ->addArgument(
                'packages',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Packages to install (e.g., lodash express@4.0.0)'
            )
            ->addOption(
                'save-dev',
                'D',
                InputOption::VALUE_NONE,
                'Save packages to devDependencies'
            )
            ->addOption(
                'save-optional',
                'O',
                InputOption::VALUE_NONE,
                'Save packages to optionalDependencies'
            )
            ->addOption(
                'save-peer',
                null,
                InputOption::VALUE_NONE,
                'Save packages to peerDependencies'
            )
            ->addOption(
                'registry',
                null,
                InputOption::VALUE_REQUIRED,
                'Registry URL to use'
            )
            ->addOption(
                'no-save',
                null,
                InputOption::VALUE_NONE,
                'Do not save to package.json'
            )
            ->setHelp(<<<'HELP'
The <info>install</info> command installs all dependencies defined in package.json.

To install all dependencies:
    <info>php-npm install</info>

To add a new package:
    <info>php-npm install lodash</info>

To add a specific version:
    <info>php-npm install lodash@4.17.21</info>

To add as dev dependency:
    <info>php-npm install -D jest</info>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $packages = $input->getArgument('packages');
        $path = getcwd();

        // Build options
        $options = [
            'registry' => $input->getOption('registry'),
        ];

        // Check if adding packages
        if (!empty($packages)) {
            $options['add'] = $packages;
            $options['save'] = !$input->getOption('no-save');

            if ($input->getOption('save-dev')) {
                $options['saveDev'] = true;
            } elseif ($input->getOption('save-optional')) {
                $options['saveOptional'] = true;
            } elseif ($input->getOption('save-peer')) {
                $options['savePeer'] = true;
            }
        }

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

            if (!empty($packages)) {
                $io->section('Adding packages');
                $arborist->add($packages, $options);
                $io->success(sprintf('Added %d package(s)', count($packages)));
            } else {
                $io->section('Installing dependencies');
                $arborist->install($options);
                $io->success('Dependencies installed successfully');
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
}
