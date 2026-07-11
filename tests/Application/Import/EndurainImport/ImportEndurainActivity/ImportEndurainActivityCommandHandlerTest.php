<?php

namespace App\Tests\Application\Import\EndurainImport\ImportEndurainActivity;

use App\Application\Import\EndurainImport\ImportEndurainActivity\ImportEndurainActivity;
use App\Application\Import\EndurainImport\ImportEndurainActivity\ImportEndurainActivityCommandHandler;
use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Endurain\Endurain;
use App\Tests\SpyOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ImportEndurainActivityCommandHandlerTest extends TestCase
{
    private MockObject $endurain;
    private MockObject $activityRepository;
    private ImportEndurainActivityCommandHandler $handler;

    public function testHandleAddsNewActivity(): void
    {
        $rawData = $this->buildRawEndurainActivity();

        $this->endurain
            ->expects($this->once())
            ->method('getActivity')
            ->with(1)
            ->willReturn($rawData);

        $this->activityRepository
            ->expects($this->once())
            ->method('exists')
            ->with(ActivityId::fromUnprefixed('endurain-1'))
            ->willReturn(false);

        $this->activityRepository
            ->expects($this->once())
            ->method('add')
            ->with($this->callback(function (ActivityWithRawData $activityWithRawData) use ($rawData): bool {
                $this->assertEquals(ActivityId::fromUnprefixed('endurain-1'), $activityWithRawData->getActivity()->getId());
                $this->assertEquals($rawData, $activityWithRawData->getRawData());

                return true;
            }));

        $this->activityRepository
            ->expects($this->never())
            ->method('update');

        $output = new SpyOutput();
        $this->handler->handle(new ImportEndurainActivity(
            output: $output,
            endurainActivityId: 1,
        ));

        $this->assertStringContainsString('Imported', (string) $output);
    }

    public function testHandleUpdatesExistingActivity(): void
    {
        $rawData = $this->buildRawEndurainActivity();

        $this->endurain
            ->expects($this->once())
            ->method('getActivity')
            ->with(1)
            ->willReturn($rawData);

        $this->activityRepository
            ->expects($this->once())
            ->method('exists')
            ->with(ActivityId::fromUnprefixed('endurain-1'))
            ->willReturn(true);

        $this->activityRepository
            ->expects($this->once())
            ->method('findWithRawData')
            ->with(ActivityId::fromUnprefixed('endurain-1'))
            ->willReturn(ActivityWithRawData::fromState(
                activity: Activity::createFromRawEndurainData($rawData),
                rawData: ['existing' => 'data'],
            ));

        $this->activityRepository
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function (ActivityWithRawData $activityWithRawData) use ($rawData): bool {
                $this->assertEquals(ActivityId::fromUnprefixed('endurain-1'), $activityWithRawData->getActivity()->getId());
                $this->assertEquals(['existing' => 'data', ...$rawData], $activityWithRawData->getRawData());

                return true;
            }));

        $this->activityRepository
            ->expects($this->never())
            ->method('add');

        $output = new SpyOutput();
        $this->handler->handle(new ImportEndurainActivity(
            output: $output,
            endurainActivityId: 1,
        ));

        $this->assertStringContainsString('Updated', (string) $output);
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

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->endurain = $this->createMock(Endurain::class);
        $this->activityRepository = $this->createMock(ActivityRepository::class);

        $this->handler = new ImportEndurainActivityCommandHandler(
            endurain: $this->endurain,
            activityRepository: $this->activityRepository,
        );
    }
}
