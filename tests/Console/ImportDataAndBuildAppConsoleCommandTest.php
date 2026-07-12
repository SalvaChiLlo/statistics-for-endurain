<?php

namespace App\Tests\Console;

use App\Application\AppStatusChecker;
use App\Application\AppUrl;
use App\Application\RebuildStatus;
use App\Console\Daemon\RunFileImportAndBuildAppConsoleCommand;
use App\Console\ImportDataAndBuildAppConsoleCommand;
use App\Domain\Activity\ActivityIdRepository;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Import\ImportMode;
use App\Domain\Import\WatchDirectory;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\KeyValue\KeyValueStore;
use App\Infrastructure\Mutex\LockName;
use App\Infrastructure\Mutex\Mutex;
use App\Infrastructure\Serialization\Json;
use App\Tests\Domain\Activity\ActivityBuilder;
use App\Tests\Infrastructure\CQRS\Command\Bus\SpyCommandBus;
use App\Tests\Infrastructure\FileSystem\SuccessfulPermissionChecker;
use App\Tests\Infrastructure\Time\Clock\PausedClock;
use App\Tests\Infrastructure\Time\ResourceUsage\FixedResourceUsage;
use League\Flysystem\FilesystemOperator;
use Psr\Log\NullLogger;
use Spatie\Snapshots\MatchesSnapshots;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ImportDataAndBuildAppConsoleCommandTest extends ConsoleCommandTestCase
{
    use MatchesSnapshots;

    private const string TODAY = '2025-12-04';

    private ImportDataAndBuildAppConsoleCommand $command;
    private SpyCommandBus $spyCommandBus;

    public function testDelegatesImportToFileImport(): void
    {
        $watchStorage = $this->getContainer()->get('default.storage');
        \assert($watchStorage instanceof FilesystemOperator);
        $watchStorage->deleteDirectory('watch');
        $watchStorage->write('watch/ride.fit', 'raw-fit-bytes');

        $command = $this->getCommandInApplication('app:data:import');
        $command->getApplication()->addCommand($this->buildFileImportCommand());

        $commandTester = new CommandTester($command);
        $commandTester->execute(['command' => 'app:data:import']);

        $this->assertMatchesJsonSnapshot(Json::encode($this->spyCommandBus->getDispatchedCommands()));
    }

    public function testDelegatesBuildToFileImport(): void
    {
        $command = $this->getCommandInApplication('app:data:build');
        $command->getApplication()->addCommand($this->buildFileImportCommand());

        $commandTester = new CommandTester($command);
        $commandTester->execute(['command' => 'app:data:build']);

        $this->assertMatchesJsonSnapshot(Json::encode($this->spyCommandBus->getDispatchedCommands()));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->getContainer()->get(ActivityRepository::class)->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()->build(),
            [],
        ));

        $this->command = new ImportDataAndBuildAppConsoleCommand(
            new NullLogger(),
        );
    }

    private function buildFileImportCommand(): RunFileImportAndBuildAppConsoleCommand
    {
        return new RunFileImportAndBuildAppConsoleCommand(
            commandBus: $this->spyCommandBus = new SpyCommandBus(),
            appStatusChecker: new AppStatusChecker(
                $this->getContainer()->get(SettingsRepository::class),
                $this->getContainer()->get(ActivityIdRepository::class),
                new SuccessfulPermissionChecker(),
            ),
            watchDirectory: $this->getContainer()->get(WatchDirectory::class),
            resourceUsage: new FixedResourceUsage(),
            mutex: new Mutex(
                connection: $this->getConnection(),
                clock: PausedClock::fromString(self::TODAY),
                lockName: LockName::IMPORT_DATA_OR_BUILD_APP,
            ),
            appUrl: AppUrl::fromString('http://localhost'),
            clock: PausedClock::fromString(self::TODAY),
            keyValueStore: $this->getContainer()->get(KeyValueStore::class),
            logger: new NullLogger(),
            importMode: ImportMode::FILES,
            rebuildStatus: $this->getContainer()->get(RebuildStatus::class),
        );
    }

    protected function getConsoleCommand(): Command
    {
        return $this->command;
    }
}
