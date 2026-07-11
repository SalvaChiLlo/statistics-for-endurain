<?php

declare(strict_types=1);

namespace App\Tests\Console\Daemon;

use App\Application\AppStatusChecker;
use App\Application\Import\CalculateActivityMetrics\CalculateActivityMetrics;
use App\Application\Import\EndurainImport\DetectEndurainActivityChanges\DetectEndurainActivityChanges;
use App\Application\Import\EndurainImport\ImportEndurainActivity\ImportEndurainActivity;
use App\Console\Daemon\RunEndurainImportAndBuildAppConsoleCommand;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityIdRepository;
use App\Domain\Activity\ActivityIds;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Endurain\Endurain;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Mutex\LockName;
use App\Infrastructure\Mutex\Mutex;
use App\Infrastructure\Serialization\Json;
use App\Tests\Console\ConsoleCommandTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;
use App\Tests\Infrastructure\CQRS\Command\Bus\SpyCommandBus;
use App\Tests\Infrastructure\FileSystem\SuccessfulPermissionChecker;
use App\Tests\Infrastructure\Time\Clock\PausedClock;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spatie\Snapshots\MatchesSnapshots;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RunEndurainImportAndBuildAppConsoleCommandTest extends ConsoleCommandTestCase
{
    use MatchesSnapshots;

    private const string TODAY = '2025-12-04';

    private RunEndurainImportAndBuildAppConsoleCommand $command;
    private SpyCommandBus $commandBus;
    private MockObject $endurain;
    private DetectEndurainActivityChanges $detectEndurainActivityChanges;
    private MockObject $activityRepository;
    private MockObject $activityIdRepository;

    public function testRunImportsNewActivitiesMarksDeletionsAndBuilds(): void
    {
        $this->endurain
            ->expects($this->once())
            ->method('getCurrentUserId')
            ->willReturn(1);

        $this->endurain
            ->expects($this->once())
            ->method('getActivities')
            ->with(userId: 1)
            ->willReturn([['id' => 1], ['id' => 2]]);

        // endurain-2 is already imported locally and still present remotely (untouched),
        // endurain-99 is imported locally but no longer present remotely (delete candidate),
        // endurain-1 is new remotely and must be imported.
        $this->activityIdRepository
            ->expects($this->atLeastOnce())
            ->method('findAllImportedFromEndurainApi')
            ->willReturn(ActivityIds::fromArray([
                ActivityId::fromUnprefixed('endurain-2'),
                ActivityId::fromUnprefixed('endurain-99'),
            ]));

        $this->activityRepository
            ->expects($this->once())
            ->method('markActivitiesForDeletion')
            ->with(ActivityIds::fromArray([ActivityId::fromUnprefixed('endurain-99')]));

        $command = $this->getCommandInApplication(RunEndurainImportAndBuildAppConsoleCommand::NAME);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['command' => $command->getName()]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertMatchesJsonSnapshot(Json::encode($this->commandBus->getDispatchedCommands()));
    }

    public function testImportsNothingWhenDiffHasNoNewActivities(): void
    {
        $this->endurain->expects($this->atLeastOnce())->method('getCurrentUserId')->willReturn(1);
        $this->endurain->expects($this->atLeastOnce())->method('getActivities')->willReturn([['id' => 1]]);

        $this->activityIdRepository
            ->expects($this->atLeastOnce())
            ->method('findAllImportedFromEndurainApi')
            ->willReturn(ActivityIds::fromArray([ActivityId::fromUnprefixed('endurain-1')]));

        $this->activityRepository
            ->expects($this->never())
            ->method('markActivitiesForDeletion');

        $command = $this->getCommandInApplication(RunEndurainImportAndBuildAppConsoleCommand::NAME);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['command' => $command->getName()]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());

        $dispatchedCommandClasses = array_map(
            static fn (object $command): string => $command::class,
            $this->commandBus->getDispatchedCommands()
        );
        $this->assertNotContains(ImportEndurainActivity::class, $dispatchedCommandClasses);
        $this->assertContains(CalculateActivityMetrics::class, $dispatchedCommandClasses);
    }

    public function testRunningTwiceWithNoRemoteChangesIsANoOpForImports(): void
    {
        $this->endurain->expects($this->atLeastOnce())->method('getCurrentUserId')->willReturn(1);
        $this->endurain->expects($this->atLeastOnce())->method('getActivities')->willReturn([['id' => 1]]);

        // Activity 1 is already locally imported both times, so the diff is empty on every run.
        $this->activityIdRepository
            ->expects($this->atLeastOnce())
            ->method('findAllImportedFromEndurainApi')
            ->willReturn(ActivityIds::fromArray([ActivityId::fromUnprefixed('endurain-1')]));

        $this->activityRepository
            ->expects($this->never())
            ->method('markActivitiesForDeletion');

        $command = $this->getCommandInApplication(RunEndurainImportAndBuildAppConsoleCommand::NAME);

        $firstRun = new CommandTester($command);
        $firstRun->execute(['command' => $command->getName()]);
        $firstDispatched = array_map(static fn (object $c): string => $c::class, $this->commandBus->getDispatchedCommands());

        $secondRun = new CommandTester($command);
        $secondRun->execute(['command' => $command->getName()]);
        $secondDispatched = array_map(static fn (object $c): string => $c::class, $this->commandBus->getDispatchedCommands());

        $this->assertNotContains(ImportEndurainActivity::class, $firstDispatched);
        $this->assertNotContains(ImportEndurainActivity::class, $secondDispatched);
    }

    public function testAbortsAndReleasesMutexWhenAllLocalActivitiesWouldBeMarkedForDeletion(): void
    {
        $this->endurain->expects($this->atLeastOnce())->method('getCurrentUserId')->willReturn(1);
        // Nothing remains remotely, so every locally imported Endurain activity becomes a
        // deletion candidate - this must be treated as a probable configuration issue.
        $this->endurain->expects($this->atLeastOnce())->method('getActivities')->willReturn([]);

        $this->activityIdRepository
            ->expects($this->atLeastOnce())
            ->method('findAllImportedFromEndurainApi')
            ->willReturn(ActivityIds::fromArray([
                ActivityId::fromUnprefixed('endurain-1'),
                ActivityId::fromUnprefixed('endurain-2'),
            ]));

        $this->activityRepository
            ->expects($this->never())
            ->method('markActivitiesForDeletion');

        $application = new Application();
        $application->addCommand($this->command);
        $commandTester = new CommandTester($application->find(RunEndurainImportAndBuildAppConsoleCommand::NAME));

        try {
            $commandTester->execute(['command' => RunEndurainImportAndBuildAppConsoleCommand::NAME]);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'All activities appear to be marked for deletion. This seems like a configuration issue. Aborting to prevent data loss',
                $e->getMessage()
            );
        }

        // The mutex must have been released on the guard-triggered abort path, otherwise a
        // subsequent run would postpone forever.
        $row = $this->getConnection()->fetchOne(
            'SELECT `value` FROM KeyValue WHERE `key` = :key',
            ['key' => 'lock.importDataOrBuildApp']
        );
        $this->assertFalse($row);
    }

    public function testDoesNotAbortWhenSomeButNotAllActivitiesAreMarkedForDeletion(): void
    {
        $this->endurain->expects($this->atLeastOnce())->method('getCurrentUserId')->willReturn(1);
        $this->endurain->expects($this->atLeastOnce())->method('getActivities')->willReturn([['id' => 2]]);

        // endurain-1 is gone remotely (delete candidate), endurain-2 is still present:
        // not everything is being deleted, so the guard must not trigger.
        $this->activityIdRepository
            ->expects($this->atLeastOnce())
            ->method('findAllImportedFromEndurainApi')
            ->willReturn(ActivityIds::fromArray([
                ActivityId::fromUnprefixed('endurain-1'),
                ActivityId::fromUnprefixed('endurain-2'),
            ]));

        $this->activityRepository
            ->expects($this->once())
            ->method('markActivitiesForDeletion')
            ->with(ActivityIds::fromArray([ActivityId::fromUnprefixed('endurain-1')]));

        $command = $this->getCommandInApplication(RunEndurainImportAndBuildAppConsoleCommand::NAME);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['command' => $command->getName()]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPostponesWhenLockIsAlreadyAcquired(): void
    {
        $this->getConnection()->executeStatement(
            'INSERT INTO KeyValue (`key`, `value`) VALUES (:key, :value)',
            ['key' => 'lock.importDataOrBuildApp', 'value' => '{"lockAcquiredBy": "test", "heartbeat": 1764806400}']
        );

        $command = $this->getCommandInApplication(RunEndurainImportAndBuildAppConsoleCommand::NAME);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['command' => $command->getName()]);

        $this->assertEmpty($this->commandBus->getDispatchedCommands());
        $this->assertStringContainsString(
            'Postponing Endurain import, another process is importing data.',
            $commandTester->getDisplay(),
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLogsAndRethrowsAndReleasesMutexWhenImportFails(): void
    {
        $this->endurain
            ->expects($this->atLeastOnce())
            ->method('getCurrentUserId')
            ->willThrowException(new \RuntimeException('OH NO ERROR'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('OH NO ERROR');

        $command = $this->buildCommand(
            commandBus: $this->commandBus,
            logger: $logger,
        );

        $this->expectExceptionObject(new \RuntimeException('OH NO ERROR'));

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($application->find(RunEndurainImportAndBuildAppConsoleCommand::NAME));

        try {
            $commandTester->execute(['command' => RunEndurainImportAndBuildAppConsoleCommand::NAME]);
        } finally {
            // The mutex must have been released even though an exception was thrown.
            $row = $this->getConnection()->fetchOne(
                'SELECT `value` FROM KeyValue WHERE `key` = :key',
                ['key' => 'lock.importDataOrBuildApp']
            );
            $this->assertFalse($row);
        }
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->getContainer()->get(ActivityRepository::class)->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()->build(),
            [],
        ));

        $this->endurain = $this->createMock(Endurain::class);
        $this->activityRepository = $this->createMock(ActivityRepository::class);
        $this->activityIdRepository = $this->createMock(ActivityIdRepository::class);
        // DetectEndurainActivityChanges is a final, pure class: use the real implementation
        // backed by the mocked ActivityIdRepository rather than trying to double it.
        $this->detectEndurainActivityChanges = new DetectEndurainActivityChanges($this->activityIdRepository);

        $this->command = $this->buildCommand(commandBus: $this->commandBus = new SpyCommandBus());
    }

    private function buildCommand(
        CommandBus $commandBus,
        ?LoggerInterface $logger = null,
    ): RunEndurainImportAndBuildAppConsoleCommand {
        return new RunEndurainImportAndBuildAppConsoleCommand(
            commandBus: $commandBus,
            endurain: $this->endurain,
            detectEndurainActivityChanges: $this->detectEndurainActivityChanges,
            activityRepository: $this->activityRepository,
            activityIdRepository: $this->activityIdRepository,
            logger: $logger ?? new NullLogger(),
            mutex: new Mutex(
                connection: $this->getConnection(),
                clock: PausedClock::fromString(self::TODAY),
                lockName: LockName::IMPORT_DATA_OR_BUILD_APP,
            ),
            appStatusChecker: new AppStatusChecker(
                $this->getContainer()->get(SettingsRepository::class),
                $this->getContainer()->get(ActivityIdRepository::class),
                new SuccessfulPermissionChecker(),
            ),
        );
    }

    protected function getConsoleCommand(): Command
    {
        return $this->command;
    }
}
