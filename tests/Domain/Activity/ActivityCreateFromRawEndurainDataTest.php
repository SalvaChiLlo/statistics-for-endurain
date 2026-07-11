<?php

namespace App\Tests\Domain\Activity;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\WorldType;
use App\Domain\Gear\GearId;
use App\Infrastructure\ValueObject\Measurement\Velocity\KmPerHour;
use PHPUnit\Framework\TestCase;

class ActivityCreateFromRawEndurainDataTest extends TestCase
{
    public function testCreateFromRawEndurainData(): void
    {
        $activity = Activity::createFromRawEndurainData($this->buildRawEndurainActivity());

        $this->assertEquals(ActivityId::fromUnprefixed('endurain-1'), $activity->getId());
        $this->assertEquals(ImportSource::ENDURAIN_API, $activity->getImportSource());
        $this->assertEquals(SportType::RIDE, $activity->getSportType());
        $this->assertEquals(WorldType::REAL_WORLD, $activity->getWorldType());
        $this->assertEquals('Workout', $activity->getName());
        $this->assertNull($activity->getExternalReferenceId());

        $this->assertEquals('2026-06-22 19:11:56', (string) $activity->getStartDate());

        // 18736 meters => 18.736 km.
        $this->assertEquals(18.736, $activity->getDistance()->toFloat());
        // elevation_gain, rounded.
        $this->assertEquals(57, $activity->getElevation()->toFloat());

        $this->assertEquals(
            KmPerHour::from(round(4.656 * 3.6, 3)),
            $activity->getAverageSpeed()
        );
        $this->assertEquals(
            KmPerHour::from(round(12.765 * 3.6, 3)),
            $activity->getMaxSpeed()
        );

        $this->assertEquals(4024, $activity->getMovingTimeInSeconds());
        $this->assertEquals(4165, $activity->getElapsedTimeInSeconds());

        $this->assertNull($activity->getAveragePower());
        $this->assertNull($activity->getMaxPower());
        $this->assertNull($activity->getAverageHeartRate());
        $this->assertNull($activity->getMaxHeartRate());
        $this->assertNull($activity->getAverageCadence());
        $this->assertEquals(0, $activity->getCalories());
        $this->assertNull($activity->getGearId());
        $this->assertFalse($activity->isCommute());
        $this->assertNull($activity->getWorkoutType());
        $this->assertNull($activity->getDeviceName());
        $this->assertNull($activity->getStartingCoordinate());
        $this->assertEquals(0, $activity->getTotalImageCount());
    }

    public function testCreateFromRawEndurainDataCombinesTrackerManufacturerAndModelIntoDeviceName(): void
    {
        $activity = Activity::createFromRawEndurainData([
            ...$this->buildRawEndurainActivity(),
            'tracker_manufacturer' => 'Garmin',
            'tracker_model' => 'Edge 1040',
        ]);

        $this->assertEquals('Garmin Edge 1040', $activity->getDeviceName());
    }

    public function testCreateFromRawEndurainDataWithNullGearIdHasNoGear(): void
    {
        $activity = Activity::createFromRawEndurainData([
            ...$this->buildRawEndurainActivity(),
            'gear_id' => null,
        ]);

        $this->assertNull($activity->getGearId());
    }

    public function testCreateFromRawEndurainDataWithGearIdBuildsAPrefixedGearId(): void
    {
        $activity = Activity::createFromRawEndurainData([
            ...$this->buildRawEndurainActivity(),
            'gear_id' => 42,
        ]);

        $this->assertEquals(GearId::fromUnprefixed('endurain-42'), $activity->getGearId());
    }

    /**
     * @return array<mixed>
     */
    private function buildRawEndurainActivity(): array
    {
        return [
            'id' => 1,
            'user_id' => 1,
            'description' => null,
            'private_notes' => null,
            'distance' => 18736,
            'name' => 'Workout',
            'activity_type' => 4,
            'start_time' => '2026-06-22T19:11:56',
            'start_time_tz_applied' => '2026-06-22T19:11:56',
            'end_time' => '2026-06-22T20:21:21',
            'end_time_tz_applied' => '2026-06-22T20:21:21',
            'timezone' => 'Europe/Madrid',
            'total_elapsed_time' => 4165.0,
            'total_timer_time' => 4024.0,
            'city' => null,
            'town' => null,
            'country' => null,
            'elevation_gain' => 57,
            'elevation_loss' => 66,
            'pace' => 0.2147712905,
            'average_speed' => 4.656,
            'max_speed' => 12.765,
            'average_power' => null,
            'max_power' => null,
            'normalized_power' => null,
            'average_hr' => null,
            'max_hr' => null,
            'average_cad' => null,
            'max_cad' => null,
            'calories' => null,
            'visibility' => 0,
            'gear_id' => null,
            'strava_gear_id' => null,
            'strava_activity_id' => null,
            'import_info' => [
                'imported' => true,
                'import_source' => 'Basic bulk import',
                'import_ISO_time' => '2026-06-22T20:25:00',
            ],
            'tracker_manufacturer' => null,
            'tracker_model' => null,
            'map_thumbnail_path' => '/app/backend/data/activity_thumbnails/1.png',
        ];
    }
}
