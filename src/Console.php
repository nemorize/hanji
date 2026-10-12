<?php

declare(strict_types=1);

namespace Hanji;

use Hanji\Output\Publisher;
use Hanji\Support\Paths;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Console
{
    public static function application(): Application
    {
        $application = new Application('Hanji', '0.1.0-dev');
        $build = (new Command('build'))->setDescription('Build the complete static site.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Configuration file, returning Hanji\\Config.');
        $build->setCode(self::handle(static function (InputInterface $input, OutputInterface $output): void {
            $result = (new Builder())->build(self::settings($input));
            $output->writeln(sprintf('Built %d pages and %d assets in %.2fs → %s', $result->pages, $result->assets, $result->seconds, $result->output));
        }));
        $clean = (new Command('clean'))->setDescription('Remove output and cache owned by this project.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Configuration file.');
        $clean->setCode(self::handle(static function (InputInterface $input, OutputInterface $output): void {
            $publisher = new Publisher(self::settings($input));
            try {
                $publisher->clean();
            } finally {
                $publisher->close();
            }
            $output->writeln('Cleaned owned output and cache.');
        }));
        $init = (new Command('init'))->setDescription('Create a small Blade site without overwriting files.')
            ->addArgument('directory', InputArgument::OPTIONAL, 'Project directory.', '.');
        $init->setCode(self::handle(static function (InputInterface $input, OutputInterface $output): void {
            $directory = $input->getArgument('directory');
            if (!is_string($directory)) {
                throw new ConfigurationException('directory must be a string.');
            }
            (new Initializer())->initialize(Paths::absolute($directory, getcwd() ?: '.'));
            $output->writeln('Created hanji.php and a Blade/CSS starter site. Run hanji build in that directory.');
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
     * @param callable(InputInterface, OutputInterface): void $action
     * @return \Closure(InputInterface, OutputInterface): int
     */
    private static function handle(callable $action): \Closure
    {
        return static function (InputInterface $input, OutputInterface $output) use ($action): int {
            try {
                $action($input, $output);
                return Command::SUCCESS;
            } catch (\Throwable $error) {
                $output->writeln('<error>' . OutputFormatter::escape($error->getMessage()) . '</error>');
                if ($output->isVerbose()) {
                    $output->writeln(OutputFormatter::escape($error->getTraceAsString()));
                }
                return $error instanceof ConfigurationException ? Command::INVALID : Command::FAILURE;
            }
        };
    }
}
