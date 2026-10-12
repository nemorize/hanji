<?php

declare(strict_types=1);

namespace Hanji;

use Hanji\Output\Publisher;
use Hanji\Console\Reporter;
use Hanji\Support\Paths;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Console
{
    public const VERSION = '0.1.0-dev';

    public static function application(): Application
    {
        $application = new Application('Hanji', self::VERSION);
        $build = (new Command('build'))->setDescription('Build the complete static site.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Configuration file, returning Hanji\\Config.');
        $build->setCode(self::handle('build', static function (InputInterface $input, Reporter $reporter): void {
            $settings = self::settings($input);
            $reporter->project($settings);
            $result = (new Builder())->build($settings, $reporter->progress(...));
            $reporter->built($result);
        }));
        $clean = (new Command('clean'))->setDescription('Remove output and cache owned by this project.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Configuration file.');
        $clean->setCode(self::handle('clean', static function (InputInterface $input, Reporter $reporter): void {
            $settings = self::settings($input);
            $reporter->project($settings);
            $publisher = new Publisher($settings);
            try {
                $removed = $publisher->clean();
            } finally {
                $publisher->close();
            }
            $reporter->cleaned($removed);
        }));
        $init = (new Command('init'))->setDescription('Create a small Blade site without overwriting files.')
            ->addArgument('directory', InputArgument::OPTIONAL, 'Project directory.', '.');
        $init->setCode(self::handle('init', static function (InputInterface $input, Reporter $reporter): void {
            $directory = $input->getArgument('directory');
            if (!is_string($directory)) {
                throw new ConfigurationException('directory must be a string.');
            }
            $directory = Paths::absolute($directory, getcwd() ?: '.');
            (new Initializer())->initialize($directory);
            $reporter->initialized($directory);
        }));
        $application->addCommands([$build, $clean, $init]);
        return $application;
    }

    private static function settings(InputInterface $input): Settings
    {
        return (new ConfigLoader())->load(getcwd() ?: '.', self::stringOption($input, 'config'));
    }

    private static function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if ($value !== null && !is_string($value)) {
            throw new ConfigurationException("{$name} must be a string.");
        }
        return $value;
    }

    /**
     * @param callable(InputInterface, Reporter): void $action
     * @return \Closure(InputInterface, OutputInterface): int
     */
    private static function handle(string $command, callable $action): \Closure
    {
        return static function (InputInterface $input, OutputInterface $output) use ($command, $action): int {
            $reporter = new Reporter($output, getcwd() ?: '.');
            try {
                $reporter->start($command, self::VERSION);
                $action($input, $reporter);
                return Command::SUCCESS;
            } catch (\Throwable $error) {
                $reporter->error($error);
                return $error instanceof ConfigurationException ? Command::INVALID : Command::FAILURE;
            }
        };
    }
}
