<?php

declare(strict_types=1);

namespace App\Console\Endurain;

use App\Application\Import\EndurainImport\ImportEndurainActivity\ImportEndurainActivity;
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

#[WithMonologChannel('console-output')]
#[RequiresUpToDateDatabaseSchema]
#[AsCommand(name: EndurainImportActivityConsoleCommand::NAME, description: 'Import a single activity from Endurain by id')]
final class EndurainImportActivityConsoleCommand extends Command
{
    public const string NAME = 'app:endurain:import-activity';
    public const string ACTIVITY_ID_ARGUMENT = 'activityId';

    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            self::ACTIVITY_ID_ARGUMENT,
            InputArgument::REQUIRED,
            'The Endurain activity id to import',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new SymfonyStyle($input, new LoggableConsoleOutput($output, $this->logger));

        /** @var string $activityIdArgument */
        $activityIdArgument = $input->getArgument(self::ACTIVITY_ID_ARGUMENT);

        $this->commandBus->dispatch(new ImportEndurainActivity(
            output: $output,
            endurainActivityId: (int) $activityIdArgument,
        ));

        return Command::SUCCESS;
    }
}
