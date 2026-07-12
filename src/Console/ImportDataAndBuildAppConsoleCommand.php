<?php

declare(strict_types=1);

namespace App\Console;

use App\Console\Daemon\RunFileImportAndBuildAppConsoleCommand;
use App\Infrastructure\Console\ProvideConsoleIntro;
use App\Infrastructure\Logging\LoggableConsoleOutput;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @deprecated Use app:cron:run-file-import */
#[AsCommand(name: 'app:data:import|app:data:build', description: 'Import and build activity data')]
final class ImportDataAndBuildAppConsoleCommand extends Command
{
    use ProvideConsoleIntro;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new SymfonyStyle($input, new LoggableConsoleOutput($output, $this->logger));
        $this->outputConsoleIntro($output);

        $application = $this->getApplication();
        $usedConsoleCommand = $input->getFirstArgument();
        assert($application instanceof Application);

        $optionToUse = match ($usedConsoleCommand) {
            'app:data:import' => RunFileImportAndBuildAppConsoleCommand::IMPORT_OPTION,
            'app:data:build' => RunFileImportAndBuildAppConsoleCommand::BUILD_OPTION,
            default => throw new \RuntimeException(sprintf('Unknown command "%s"', $usedConsoleCommand)),
        };

        $arrayInput = [
            'command' => RunFileImportAndBuildAppConsoleCommand::NAME,
            '--'.$optionToUse => true,
        ];

        $input = new ArrayInput($arrayInput);
        $input->setInteractive(false);

        return $application->doRun($input, $output);
    }
}
