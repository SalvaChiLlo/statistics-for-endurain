<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Console\MigrateFromStatisticsForStravaConsoleCommand;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class MigrateFromStatisticsForStravaConsoleCommandTest extends ConsoleCommandTestCase
{
    private string $sourceDatabaseFilePath;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDatabaseFilePath = sys_get_temp_dir().'/legacy-strava-console-'.uniqid('', true).'.db';
        $pdo = new \PDO('sqlite:'.$this->sourceDatabaseFilePath);
        $pdo->exec('CREATE TABLE Activity (
            activityId TEXT PRIMARY KEY, startDateTime TEXT, sportType TEXT, importSource TEXT,
            name TEXT, distance REAL, elevation REAL, averageSpeed REAL, maxSpeed REAL,
            movingTimeInSeconds INTEGER, elapsedTimeInSeconds INTEGER, gearId TEXT, data TEXT, isCommute INTEGER
        )');
        $pdo->exec('CREATE TABLE Gear (gearId TEXT PRIMARY KEY, createdOn TEXT, name TEXT, isRetired INTEGER, type TEXT)');
        $pdo->exec('CREATE TABLE ActivityStream (activityId TEXT, streamType TEXT, data TEXT, createdOn TEXT)');
        $pdo->exec("INSERT INTO Activity (activityId, startDateTime, sportType, importSource, name, distance, elevation, averageSpeed, maxSpeed, movingTimeInSeconds, elapsedTimeInSeconds, gearId, data, isCommute)
            VALUES ('activity-console-1', '2020-01-01 08:00:00', 'Ride', 'stravaApi', 'Morning ride', 10000, 100, 20.0, 30.0, 1800, 1900, NULL, '{}', 0)");
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_file($this->sourceDatabaseFilePath)) {
            unlink($this->sourceDatabaseFilePath);
        }
    }

    public function testExecuteMigratesActivity(): void
    {
        $command = $this->getCommandInApplication(MigrateFromStatisticsForStravaConsoleCommand::NAME);
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'command' => $command->getName(),
            'sourceDatabaseFilePath' => $this->sourceDatabaseFilePath,
        ]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertTrue($this->getContainer()->get(ActivityRepository::class)->exists(ActivityId::fromUnprefixed('console-1')));
    }

    public function testExecuteFailsGracefullyWhenSourceFileDoesNotExist(): void
    {
        $command = $this->getCommandInApplication(MigrateFromStatisticsForStravaConsoleCommand::NAME);
        $commandTester = new CommandTester($command);

        $this->expectException(\RuntimeException::class);
        $commandTester->execute([
            'command' => $command->getName(),
            'sourceDatabaseFilePath' => '/nonexistent/legacy.db',
        ]);
    }

    #[\Override]
    protected function getConsoleCommand(): Command
    {
        return $this->getContainer()->get(MigrateFromStatisticsForStravaConsoleCommand::class);
    }
}
