<?php

declare(strict_types=1);

namespace PhpNpm\CLI;

use Symfony\Component\Console\Application as SymfonyApplication;
use PhpNpm\CLI\Command\InstallCommand;
use PhpNpm\CLI\Command\CiCommand;
use PhpNpm\CLI\Command\UpdateCommand;
use PhpNpm\CLI\Command\ListCommand;

/**
 * Main CLI application for php-npm.
 */
class Application extends SymfonyApplication
{
    public const NAME = 'php-npm';
    public const VERSION = '1.0.0';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->registerCommands();
    }

    /**
     * Register all available commands.
     */
    private function registerCommands(): void
    {
        $this->add(new InstallCommand());
        $this->add(new CiCommand());
        $this->add(new UpdateCommand());
        $this->add(new ListCommand());
    }

    /**
     * Get the default command name.
     */
    protected function getDefaultInputDefinition(): \Symfony\Component\Console\Input\InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();

        return $definition;
    }
}
