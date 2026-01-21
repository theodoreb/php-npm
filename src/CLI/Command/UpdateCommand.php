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
 * Update packages (php-npm update).
 */
class UpdateCommand extends Command
{
    protected static $defaultName = 'update';
    protected static $defaultDescription = 'Update packages to their latest versions';

    protected function configure(): void
    {
        $this
            ->setName('update')
            ->setAliases(['up', 'upgrade'])
            ->setDescription('Update packages to their latest versions')
            ->addArgument(
                'packages',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Packages to update (empty = all)'
            )
            ->addOption(
                'registry',
                null,
                InputOption::VALUE_REQUIRED,
                'Registry URL to use'
            )
            ->setHelp(<<<'HELP'
The <info>update</info> command updates packages to their latest versions
within the ranges specified in package.json.

To update all packages:
    <info>php-npm update</info>

To update specific packages:
    <info>php-npm update lodash express</info>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $packages = $input->getArgument('packages');
        $path = getcwd();

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

            if (!empty($packages)) {
                $io->section('Updating packages: ' . implode(', ', $packages));
            } else {
                $io->section('Updating all packages');
            }

            $arborist->update($packages, $options);
            $io->success('Packages updated successfully');

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
