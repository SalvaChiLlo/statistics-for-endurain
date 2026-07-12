<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Legacy;

use App\Infrastructure\Legacy\LegacyStatisticsForStravaDatabaseReader;
use PHPUnit\Framework\TestCase;

class LegacyStatisticsForStravaDatabaseReaderTest extends TestCase
{
    private string $sourceDatabaseFilePath;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDatabaseFilePath = sys_get_temp_dir().'/legacy-strava-'.uniqid('', true).'.db';
        $this->createLegacyDatabase($this->sourceDatabaseFilePath);
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_file($this->sourceDatabaseFilePath)) {
            unlink($this->sourceDatabaseFilePath);
        }
    }

    public function testFetchActivities(): void
    {
        $reader = new LegacyStatisticsForStravaDatabaseReader($this->sourceDatabaseFilePath);

        $activities = $reader->fetchActivities();

        $this->assertCount(1, $activities);
        $this->assertSame('activity-1', $activities[0]['activityId']);
    }

    public function testFetchGear(): void
    {
        $reader = new LegacyStatisticsForStravaDatabaseReader($this->sourceDatabaseFilePath);

        $gear = $reader->fetchGear();

        $this->assertCount(1, $gear);
        $this->assertSame('gear-1', $gear[0]['gearId']);
    }

    public function testFetchActivityStreams(): void
    {
        $reader = new LegacyStatisticsForStravaDatabaseReader($this->sourceDatabaseFilePath);

        $streams = $reader->fetchActivityStreams();

        $this->assertCount(1, $streams);
        $this->assertSame('activity-1', $streams[0]['activityId']);
    }

    public function testFetchingFromMissingTableReturnsEmptyArray(): void
    {
        $emptyDbPath = sys_get_temp_dir().'/legacy-strava-empty-'.uniqid('', true).'.db';
        $pdo = new \PDO('sqlite:'.$emptyDbPath);
        $pdo->exec('CREATE TABLE SomeOtherTable (id INTEGER)');
        unset($pdo);

        $reader = new LegacyStatisticsForStravaDatabaseReader($emptyDbPath);

        $this->assertSame([], $reader->fetchActivities());
        $this->assertSame([], $reader->fetchGear());
        $this->assertSame([], $reader->fetchActivityStreams());

        unlink($emptyDbPath);
    }

    public function testThrowsWhenSourceFileDoesNotExist(): void
    {
        $reader = new LegacyStatisticsForStravaDatabaseReader('/nonexistent/path/to/legacy.db');

        $this->expectException(\RuntimeException::class);
        $reader->assertIsReadable();
    }

    public function testReadingDoesNotModifySourceDatabaseFile(): void
    {
        $checksumBefore = md5_file($this->sourceDatabaseFilePath);
        $mtimeBefore = filemtime($this->sourceDatabaseFilePath);

        $reader = new LegacyStatisticsForStravaDatabaseReader($this->sourceDatabaseFilePath);
        $reader->fetchActivities();
        $reader->fetchGear();
        $reader->fetchActivityStreams();

        clearstatcache(true, $this->sourceDatabaseFilePath);
        $this->assertSame($checksumBefore, md5_file($this->sourceDatabaseFilePath));
        $this->assertSame($mtimeBefore, filemtime($this->sourceDatabaseFilePath));
    }

    public function testConnectionIsReadOnly(): void
    {
        $reader = new LegacyStatisticsForStravaDatabaseReader($this->sourceDatabaseFilePath);
        // Force the connection to be opened.
        $reader->fetchActivities();

        // Attempting to write through a completely separate, "normal" PDO
        // connection to the same file should still succeed (sanity check
        // this isn't a filesystem-permission fluke), while our reader's own
        // connection is opened read-only and never issues any write.
        $directConnection = new \PDO('sqlite:'.$this->sourceDatabaseFilePath);
        $directConnection->exec("INSERT INTO Gear (gearId) VALUES ('gear-direct-write-check')");
        $count = (int) $directConnection->query('SELECT COUNT(*) FROM Gear')->fetchColumn();
        $this->assertSame(2, $count);
    }

    private function createLegacyDatabase(string $path): void
    {
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE Activity (
            activityId TEXT PRIMARY KEY,
            startDateTime TEXT,
            sportType TEXT,
            importSource TEXT,
            name TEXT,
            distance REAL,
            elevation REAL,
            averageSpeed REAL,
            maxSpeed REAL,
            movingTimeInSeconds INTEGER,
            elapsedTimeInSeconds INTEGER,
            gearId TEXT,
            data TEXT,
            isCommute INTEGER
        )');
        $pdo->exec('CREATE TABLE Gear (
            gearId TEXT PRIMARY KEY,
            createdOn TEXT,
            name TEXT,
            isRetired INTEGER,
            type TEXT
        )');
        $pdo->exec('CREATE TABLE ActivityStream (
            activityId TEXT,
            streamType TEXT,
            data TEXT,
            createdOn TEXT
        )');

        $pdo->exec("INSERT INTO Gear (gearId, createdOn, name, isRetired, type) VALUES ('gear-1', '2020-01-01 00:00:00', 'Old bike', 0, 'imported')");
        $pdo->exec("INSERT INTO Activity (activityId, startDateTime, sportType, importSource, name, distance, elevation, averageSpeed, maxSpeed, movingTimeInSeconds, elapsedTimeInSeconds, gearId, data, isCommute)
            VALUES ('activity-1', '2020-01-01 08:00:00', 'Ride', 'stravaApi', 'Morning ride', 10000, 100, 20.0, 30.0, 1800, 1900, 'gear-1', '{}', 0)");

        $statement = $pdo->prepare('INSERT INTO ActivityStream (activityId, streamType, data, createdOn) VALUES (:activityId, :streamType, :data, :createdOn)');
        $statement->bindValue('activityId', 'activity-1');
        $statement->bindValue('streamType', 'time');
        $statement->bindValue('data', zstd_compress('[]'), \PDO::PARAM_LOB);
        $statement->bindValue('createdOn', '2020-01-01 08:00:00');
        $statement->execute();
    }
}
