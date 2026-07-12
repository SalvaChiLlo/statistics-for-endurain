<?php

declare(strict_types=1);

namespace App\Application\Import\LegacyStravaDatabaseImport\MigrateFromStatisticsForStrava;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityName;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\Route\RouteGeography;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\Stream\ActivityStream;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Activity\WorkoutType;
use App\Domain\Activity\WorldType;
use App\Domain\Gear\Gear;
use App\Domain\Gear\GearId;
use App\Domain\Gear\GearRepository;
use App\Domain\Gear\GearType;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Legacy\LegacyStatisticsForStravaDatabaseReader;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Geography\Coordinate;
use App\Infrastructure\ValueObject\Geography\Latitude;
use App\Infrastructure\ValueObject\Geography\Longitude;
use App\Infrastructure\ValueObject\Measurement\Length\Meter;
use App\Infrastructure\ValueObject\Measurement\Velocity\KmPerHour;
use App\Infrastructure\ValueObject\String\ExternalReferenceId;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Money\Currency;
use Money\Money;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-time migration from an existing statistics-for-strava installation's
 * local SQLite database into this app's own database. No Strava API calls
 * are involved: the old database already has everything (activities,
 * streams, gear) downloaded locally.
 *
 * This deliberately does a row-level copy rather than a file-copy-and-migrate:
 * - It's idempotent by construction (existence checks before every insert),
 *   which a raw file copy is not (you'd need extra bookkeeping to make a
 *   second run safe).
 * - It never touches the source file (read-only PDO connection), whereas
 *   copying the file into place and running migrations against it implies
 *   the source becomes the live database (or a copy of it does), which is a
 *   more destructive-feeling operation for a "one-time import" command.
 * - It tolerates schema drift column-by-column (missing/new columns just get
 *   defaults), rather than requiring the old database to first be upgraded
 *   to this fork's exact schema version before the migration can run.
 */
final readonly class MigrateFromStatisticsForStravaCommandHandler implements CommandHandler
{
    public function __construct(
        private ActivityRepository $activityRepository,
        private ActivityStreamRepository $activityStreamRepository,
        private GearRepository $gearRepository,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof MigrateFromStatisticsForStrava);

        $output = $command->getOutput();
        $reader = new LegacyStatisticsForStravaDatabaseReader($command->getSourceDatabaseFilePath());
        $reader->assertIsReadable();

        $gearImported = $this->migrateGear($reader, $output);
        $activityStats = $this->migrateActivities($reader, $output);
        $streamsImported = $this->migrateActivityStreams($reader, $output);

        $output->writeln(sprintf(
            '  => Migration complete: %d gear, %d activities imported (%d already present), %d streams imported',
            $gearImported,
            $activityStats['imported'],
            $activityStats['alreadyPresent'],
            $streamsImported,
        ));
    }

    private function migrateGear(LegacyStatisticsForStravaDatabaseReader $reader, OutputInterface $output): int
    {
        $imported = 0;
        foreach ($reader->fetchGear() as $row) {
            try {
                $gearId = GearId::fromString((string) ($row['gearId'] ?? ''));
            } catch (\Throwable) {
                $output->writeln('  => [Skipped] gear row with missing/invalid gearId');
                continue;
            }

            if ($this->gearExists($gearId)) {
                continue;
            }

            try {
                $gear = $this->hydrateGear($row, $gearId);
            } catch (\Throwable $exception) {
                $output->writeln(sprintf('  => [Skipped] gear "%s": %s', $gearId, $exception->getMessage()));
                continue;
            }

            $this->gearRepository->add($gear);
            ++$imported;
        }

        return $imported;
    }

    private function gearExists(GearId $gearId): bool
    {
        try {
            $this->gearRepository->find($gearId);

            return true;
        } catch (EntityNotFound) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateGear(array $row, GearId $gearId): Gear
    {
        $gear = Gear::create(
            gearId: $gearId,
            createdOn: isset($row['createdOn']) ? SerializableDateTime::fromString((string) $row['createdOn']) : SerializableDateTime::fromString('now'),
            name: (string) ($row['name'] ?? $gearId->toUnprefixedString()),
            isRetired: (bool) ($row['isRetired'] ?? false),
            type: GearType::tryFrom((string) ($row['type'] ?? '')) ?? GearType::IMPORTED,
            localImagePath: $row['localImagePath'] ?? null,
        );

        $purchasePriceCurrency = (string) ($row['purchasePriceCurrency'] ?? '');
        if (isset($row['purchasePriceAmount']) && '' !== $purchasePriceCurrency) {
            return $gear->withPurchasePrice(new Money(
                amount: (int) $row['purchasePriceAmount'],
                currency: new Currency($purchasePriceCurrency),
            ));
        }

        return $gear;
    }

    /**
     * @return array{imported: int, alreadyPresent: int}
     */
    private function migrateActivities(LegacyStatisticsForStravaDatabaseReader $reader, OutputInterface $output): array
    {
        $imported = 0;
        $alreadyPresent = 0;

        foreach ($reader->fetchActivities() as $row) {
            try {
                $activityId = ActivityId::fromString((string) ($row['activityId'] ?? ''));
            } catch (\Throwable) {
                $output->writeln('  => [Skipped] activity row with missing/invalid activityId');
                continue;
            }

            if ($this->activityRepository->exists($activityId)) {
                ++$alreadyPresent;
                continue;
            }

            try {
                $activity = $this->hydrateActivity($row, $activityId);
                $rawData = isset($row['data']) ? (array) Json::decode((string) $row['data']) : [];
            } catch (\Throwable $exception) {
                $output->writeln(sprintf('  => [Skipped] activity "%s": %s', $activityId, $exception->getMessage()));
                continue;
            }

            $this->activityRepository->add(ActivityWithRawData::fromState(
                activity: $activity,
                rawData: $rawData,
            ));
            ++$imported;
        }

        $output->writeln(sprintf('  => Imported %d activities (%d already present)', $imported, $alreadyPresent));

        return ['imported' => $imported, 'alreadyPresent' => $alreadyPresent];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateActivity(array $row, ActivityId $activityId): Activity
    {
        if (!isset($row['startDateTime'])) {
            throw new \RuntimeException('missing startDateTime');
        }
        $startDateTime = SerializableDateTime::fromString((string) $row['startDateTime']);

        if (!isset($row['sportType'])) {
            throw new \RuntimeException('missing sportType');
        }
        $sportType = SportType::from((string) $row['sportType']);

        $name = trim((string) ($row['name'] ?? ''));

        return Activity::fromState(
            activityId: $activityId,
            startDateTime: $startDateTime,
            sportType: $sportType,
            worldType: WorldType::tryFrom((string) ($row['worldType'] ?? '')) ?? WorldType::REAL_WORLD,
            importSource: ImportSource::tryFrom((string) ($row['importSource'] ?? '')) ?? ImportSource::STRAVA_API,
            externalReferenceId: ExternalReferenceId::fromOptionalString(isset($row['externalReferenceId']) ? (string) $row['externalReferenceId'] : null),
            name: '' !== $name ? ActivityName::fromString($name) : ActivityName::from($startDateTime, $sportType),
            description: isset($row['description']) ? (string) $row['description'] : '',
            distance: Meter::from((float) ($row['distance'] ?? 0))->toKilometer(),
            elevation: Meter::from((float) ($row['elevation'] ?? 0)),
            startingCoordinate: Coordinate::createFromOptionalLatAndLng(
                isset($row['startingCoordinateLatitude']) ? Latitude::fromOptionalString((string) $row['startingCoordinateLatitude']) : null,
                isset($row['startingCoordinateLongitude']) ? Longitude::fromOptionalString((string) $row['startingCoordinateLongitude']) : null,
            ),
            calories: isset($row['calories']) ? (int) $row['calories'] : null,
            kilojoules: isset($row['kilojoules']) ? (int) $row['kilojoules'] : null,
            averagePower: isset($row['averagePower']) ? (int) $row['averagePower'] : null,
            maxPower: isset($row['maxPower']) ? (int) $row['maxPower'] : null,
            averageSpeed: KmPerHour::from((float) ($row['averageSpeed'] ?? 0)),
            maxSpeed: KmPerHour::from((float) ($row['maxSpeed'] ?? 0)),
            averageHeartRate: isset($row['averageHeartRate']) ? (int) round((float) $row['averageHeartRate']) : null,
            maxHeartRate: isset($row['maxHeartRate']) ? (int) round((float) $row['maxHeartRate']) : null,
            averageCadence: isset($row['averageCadence']) ? (int) round((float) $row['averageCadence']) : null,
            movingTimeInSeconds: isset($row['movingTimeInSeconds']) ? (int) $row['movingTimeInSeconds'] : 0,
            elapsedTimeInSeconds: isset($row['elapsedTimeInSeconds']) ? (int) $row['elapsedTimeInSeconds'] : 0,
            deviceName: isset($row['deviceName']) ? (string) $row['deviceName'] : null,
            totalImageCount: isset($row['totalImageCount']) ? (int) $row['totalImageCount'] : 0,
            localImagePaths: empty($row['localImagePaths']) ? [] : explode(',', (string) $row['localImagePaths']),
            polyline: isset($row['polyline']) ? (string) $row['polyline'] : null,
            routeGeography: RouteGeography::create(isset($row['routeGeography']) ? (array) Json::decode((string) $row['routeGeography']) : []),
            weather: isset($row['weather']) ? (string) $row['weather'] : null,
            gearId: isset($row['gearId']) && '' !== (string) $row['gearId'] ? GearId::fromString((string) $row['gearId']) : null,
            isCommute: (bool) ($row['isCommute'] ?? false),
            workoutType: isset($row['workoutType']) ? WorkoutType::tryFrom((string) $row['workoutType']) : null,
        );
    }

    private function migrateActivityStreams(LegacyStatisticsForStravaDatabaseReader $reader, OutputInterface $output): int
    {
        $imported = 0;

        foreach ($reader->fetchActivityStreams() as $row) {
            try {
                $activityId = ActivityId::fromString((string) ($row['activityId'] ?? ''));
                $streamType = StreamType::from((string) ($row['streamType'] ?? ''));
            } catch (\Throwable) {
                $output->writeln('  => [Skipped] stream row with missing/invalid activityId or streamType');
                continue;
            }

            if (!$this->activityRepository->exists($activityId)) {
                // The activity this stream belongs to was never migrated
                // (e.g. it failed to hydrate), so skip its streams too.
                continue;
            }

            if ($this->activityStreamRepository->hasOneForActivityAndStreamType($activityId, $streamType)) {
                continue;
            }

            try {
                $streamData = isset($row['data']) ? (array) Json::uncompressAndDecode((string) $row['data']) : [];
                $createdOn = isset($row['createdOn']) ? SerializableDateTime::fromString((string) $row['createdOn']) : SerializableDateTime::fromString('now');
            } catch (\Throwable $exception) {
                $output->writeln(sprintf('  => [Skipped] stream "%s-%s": %s', $activityId, $streamType->value, $exception->getMessage()));
                continue;
            }

            $this->activityStreamRepository->add(ActivityStream::fromState(
                activityId: $activityId,
                streamType: $streamType,
                streamData: $streamData,
                createdOn: $createdOn,
            ));
            ++$imported;
        }

        $output->writeln(sprintf('  => Imported %d activity streams', $imported));

        return $imported;
    }
}
