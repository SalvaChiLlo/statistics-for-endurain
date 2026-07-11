<?php

declare(strict_types=1);

namespace App\Console;

use App\Application\Import\LegacyStravaDatabaseImport\MigrateFromStatisticsForStrava\MigrateFromStatisticsForStrava;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Doctrine\Migrations\RequiresUpToDateDatabaseSchema;
use App\Infrastructure\Logging\LoggableConsoleOutput;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-time migration command: imports an existing statistics-for-strava
 * installation's SQLite database (activities, streams, gear) into this
 * app's own database, without calling the Strava API at all. Safe to
 * re-run and never writes to the source database file.
 */
#[WithMonologChannel('console-output')]
#[RequiresUpToDateDatabaseSchema]
#[AsCommand(name: MigrateFromStatisticsForStravaConsoleCommand::NAME, description: 'Migrate an existing statistics-for-strava SQLite database into this app')]
final class MigrateFromStatisticsForStravaConsoleCommand extends Command
{
    public const string NAME = 'app:migrate:from-statistics-for-strava';
    public const string SOURCE_DATABASE_ARGUMENT = 'sourceDatabaseFilePath';

    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            self::SOURCE_DATABASE_ARGUMENT,
            InputArgument::REQUIRED,
            'Path to the existing statistics-for-strava SQLite database file to migrate from',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new SymfonyStyle($input, new LoggableConsoleOutput($output, $this->logger));

        /** @var string $sourceDatabaseFilePath */
        $sourceDatabaseFilePath = $input->getArgument(self::SOURCE_DATABASE_ARGUMENT);

        $this->commandBus->dispatch(new MigrateFromStatisticsForStrava(
            output: $output,
            sourceDatabaseFilePath: $sourceDatabaseFilePath,
        ));

        return Command::SUCCESS;
    }
}
