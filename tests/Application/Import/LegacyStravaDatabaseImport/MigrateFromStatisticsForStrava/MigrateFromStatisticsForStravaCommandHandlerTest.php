<?php

declare(strict_types=1);

namespace App\Tests\Application\Import\LegacyStravaDatabaseImport\MigrateFromStatisticsForStrava;

use App\Application\Import\LegacyStravaDatabaseImport\MigrateFromStatisticsForStrava\MigrateFromStatisticsForStrava;
use App\Application\Import\LegacyStravaDatabaseImport\MigrateFromStatisticsForStrava\MigrateFromStatisticsForStravaCommandHandler;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Activity\WorldType;
use App\Domain\Gear\GearId;
use App\Domain\Gear\GearRepository;
use App\Tests\ContainerTestCase;
use App\Tests\SpyOutput;

class MigrateFromStatisticsForStravaCommandHandlerTest extends ContainerTestCase
{
    private string $sourceDatabaseFilePath;
    private MigrateFromStatisticsForStravaCommandHandler $handler;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new MigrateFromStatisticsForStravaCommandHandler(
            activityRepository: $this->getContainer()->get(ActivityRepository::class),
            activityStreamRepository: $this->getContainer()->get(ActivityStreamRepository::class),
            gearRepository: $this->getContainer()->get(GearRepository::class),
        );

        $this->sourceDatabaseFilePath = sys_get_temp_dir().'/legacy-strava-'.uniqid('', true).'.db';
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_file($this->sourceDatabaseFilePath)) {
            unlink($this->sourceDatabaseFilePath);
        }
    }

    public function testMigratesActivitiesStreamsAndGearIntoNewDatabase(): void
    {
        $this->createLegacyDatabase($this->sourceDatabaseFilePath, includeAllColumns: true);

        $this->handler->handle(new MigrateFromStatisticsForStrava(
            output: new SpyOutput(),
            sourceDatabaseFilePath: $this->sourceDatabaseFilePath,
        ));

        $activityRepository = $this->getContainer()->get(ActivityRepository::class);
        $this->assertTrue($activityRepository->exists(ActivityId::fromUnprefixed('1')));

        $activity = $activityRepository->find(ActivityId::fromUnprefixed('1'));
        $this->assertSame(ImportSource::STRAVA_API, $activity->getImportSource());
        $this->assertSame(WorldType::REAL_WORLD, $activity->getWorldType());

        $gearRepository = $this->getContainer()->get(GearRepository::class);
        $gear = $gearRepository->find(GearId::fromUnprefixed('1'));
        $this->assertSame('Old bike', $gear->getOriginalName());

        $streamRepository = $this->getContainer()->get(ActivityStreamRepository::class);
        $this->assertTrue($streamRepository->hasOneForActivityAndStreamType(ActivityId::fromUnprefixed('1'), StreamType::TIME));
    }

    public function testRunningTwiceDoesNotCreateDuplicates(): void
    {
        $this->createLegacyDatabase($this->sourceDatabaseFilePath, includeAllColumns: true);

        $this->handler->handle(new MigrateFromStatisticsForStrava(
            output: new SpyOutput(),
            sourceDatabaseFilePath: $this->sourceDatabaseFilePath,
        ));
        $this->handler->handle(new MigrateFromStatisticsForStrava(
            output: new SpyOutput(),
            sourceDatabaseFilePath: $this->sourceDatabaseFilePath,
        ));

        $activityCount = (int) $this->getConnection()->executeQuery('SELECT COUNT(*) FROM Activity')->fetchOne();
        $this->assertSame(1, $activityCount);

        $gearCount = (int) $this->getConnection()->executeQuery('SELECT COUNT(*) FROM Gear')->fetchOne();
        $this->assertSame(1, $gearCount);

        $streamCount = (int) $this->getConnection()->executeQuery('SELECT COUNT(*) FROM ActivityStream')->fetchOne();
        $this->assertSame(1, $streamCount);
    }

    public function testDoesNotModifySourceDatabaseFile(): void
    {
        $this->createLegacyDatabase($this->sourceDatabaseFilePath, includeAllColumns: true);

        $checksumBefore = md5_file($this->sourceDatabaseFilePath);

        $this->handler->handle(new MigrateFromStatisticsForStrava(
            output: new SpyOutput(),
            sourceDatabaseFilePath: $this->sourceDatabaseFilePath,
        ));

        clearstatcache(true, $this->sourceDatabaseFilePath);
        $this->assertSame($checksumBefore, md5_file($this->sourceDatabaseFilePath));
    }

    public function testHandlesMissingForkSpecificColumnsGracefully(): void
    {
        // Simulate schema drift: the old statistics-for-strava database
        // doesn't have some of the columns this fork's schema has, e.g.
        // anything Endurain-specific. The migration must not crash and
        // should fall back to sensible defaults for those columns.
        $this->createLegacyDatabase($this->sourceDatabaseFilePath, includeAllColumns: false);

        $this->handler->handle(new MigrateFromStatisticsForStrava(
            output: new SpyOutput(),
            sourceDatabaseFilePath: $this->sourceDatabaseFilePath,
        ));

        $activityRepository = $this->getContainer()->get(ActivityRepository::class);
        $this->assertTrue($activityRepository->exists(ActivityId::fromUnprefixed('1')));

        $activity = $activityRepository->find(ActivityId::fromUnprefixed('1'));
        // worldType column was absent from the source row: falls back to the default.
        $this->assertSame(WorldType::REAL_WORLD, $activity->getWorldType());
    }

    private function createLegacyDatabase(string $path, bool $includeAllColumns): void
    {
        $pdo = new \PDO('sqlite:'.$path);

        if ($includeAllColumns) {
            $pdo->exec('CREATE TABLE Activity (
                activityId TEXT PRIMARY KEY, startDateTime TEXT, sportType TEXT, worldType TEXT,
                importSource TEXT, externalReferenceId TEXT, name TEXT, description TEXT,
                distance REAL, elevation REAL, startingCoordinateLatitude REAL, startingCoordinateLongitude REAL,
                calories INTEGER, kilojoules INTEGER, averagePower INTEGER, maxPower INTEGER,
                averageSpeed REAL, maxSpeed REAL, averageHeartRate INTEGER, maxHeartRate INTEGER,
                averageCadence INTEGER, movingTimeInSeconds INTEGER, elapsedTimeInSeconds INTEGER,
                deviceName TEXT, totalImageCount INTEGER, localImagePaths TEXT, polyline TEXT,
                routeGeography TEXT, weather TEXT, gearId TEXT, data TEXT, isCommute INTEGER, workoutType TEXT
            )');
        } else {
            $pdo->exec('CREATE TABLE Activity (
                activityId TEXT PRIMARY KEY, startDateTime TEXT, sportType TEXT,
                importSource TEXT, name TEXT, distance REAL, elevation REAL,
                averageSpeed REAL, maxSpeed REAL, movingTimeInSeconds INTEGER,
                elapsedTimeInSeconds INTEGER, gearId TEXT, data TEXT, isCommute INTEGER
            )');
        }

        $pdo->exec('CREATE TABLE Gear (
            gearId TEXT PRIMARY KEY, createdOn TEXT, name TEXT, isRetired INTEGER, type TEXT,
            localImagePath TEXT, purchasePriceAmount INTEGER, purchasePriceCurrency TEXT
        )');
        $pdo->exec('CREATE TABLE ActivityStream (
            activityId TEXT, streamType TEXT, data TEXT, createdOn TEXT
        )');

        $pdo->exec("INSERT INTO Gear (gearId, createdOn, name, isRetired, type) VALUES ('gear-1', '2020-01-01 00:00:00', 'Old bike', 0, 'imported')");

        if ($includeAllColumns) {
            $pdo->exec("INSERT INTO Activity (
                activityId, startDateTime, sportType, worldType, importSource, name, description,
                distance, elevation, averageSpeed, maxSpeed, movingTimeInSeconds, elapsedTimeInSeconds,
                gearId, data, isCommute, workoutType
            ) VALUES (
                'activity-1', '2020-01-01 08:00:00', 'Ride', 'realWorld', 'stravaApi', 'Morning ride', '',
                10000, 100, 20.0, 30.0, 1800, 1900, 'gear-1', '{}', 0, NULL
            )");
        } else {
            $pdo->exec("INSERT INTO Activity (
                activityId, startDateTime, sportType, importSource, name, distance, elevation,
                averageSpeed, maxSpeed, movingTimeInSeconds, elapsedTimeInSeconds, gearId, data, isCommute
            ) VALUES (
                'activity-1', '2020-01-01 08:00:00', 'Ride', 'stravaApi', 'Morning ride', 10000, 100,
                20.0, 30.0, 1800, 1900, 'gear-1', '{}', 0
            )");
        }

        $statement = $pdo->prepare('INSERT INTO ActivityStream (activityId, streamType, data, createdOn) VALUES (:activityId, :streamType, :data, :createdOn)');
        $statement->bindValue('activityId', 'activity-1');
        $statement->bindValue('streamType', 'time');
        $statement->bindValue('data', zstd_compress('[1,2,3]'), \PDO::PARAM_LOB);
        $statement->bindValue('createdOn', '2020-01-01 08:00:00');
        $statement->execute();
    }
}
