<?php

namespace App\Tests\Application\Import\EndurainImport\ImportEndurainActivity;

use App\Application\Import\EndurainImport\ImportEndurainActivity\ImportEndurainActivity;
use App\Application\Import\EndurainImport\ImportEndurainActivity\ImportEndurainActivityCommandHandler;
use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\Stream\ActivityStream;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Endurain\Endurain;
use App\Domain\Endurain\Stream\EndurainStreamParser;
use App\Tests\Infrastructure\Time\Clock\PausedClock;
use App\Tests\SpyOutput;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ImportEndurainActivityCommandHandlerTest extends TestCase
{
    private MockObject $endurain;
    private MockObject $activityRepository;
    private MockObject $activityStreamRepository;
    private ImportEndurainActivityCommandHandler $handler;

    public function testHandleAddsNewActivityAndPersistsStreamsAndPolyline(): void
    {
        $rawData = $this->buildRawEndurainActivity();
        $rawStreams = $this->buildRawEndurainStreams();

        $this->endurain
            ->expects($this->once())
            ->method('getActivity')
            ->with(1)
            ->willReturn($rawData);

        $this->endurain
            ->expects($this->once())
            ->method('getAllActivityStreams')
            ->with(1)
            ->willReturn($rawStreams);

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
                // The polyline gets re-encoded from the raw lat/lng stream waypoints.
                $this->assertNotNull($activityWithRawData->getActivity()->getEncodedPolyline());

                return true;
            }));

        $this->activityRepository
            ->expects($this->never())
            ->method('update');

        $this->activityStreamRepository
            ->method('hasOneForActivityAndStreamType')
            ->willReturn(false);

        $persistedStreamTypes = [];
        $this->activityStreamRepository
            ->expects($this->exactly(2))
            ->method('add')
            ->with($this->callback(function (ActivityStream $stream) use (&$persistedStreamTypes): bool {
                $this->assertEquals(ActivityId::fromUnprefixed('endurain-1'), $stream->getActivityId());
                $persistedStreamTypes[] = $stream->getStreamType();

                return true;
            }));

        $this->activityRepository
            ->expects($this->once())
            ->method('markActivityStreamsAsImported')
            ->with(ActivityId::fromUnprefixed('endurain-1'));

        $output = new SpyOutput();
        $this->handler->handle(new ImportEndurainActivity(
            output: $output,
            endurainActivityId: 1,
        ));

        $this->assertStringContainsString('Imported', (string) $output);
        $this->assertEqualsCanonicalizing([StreamType::HEART_RATE, StreamType::LAT_LNG], $persistedStreamTypes);
    }

    public function testHandleSkipsStreamTypesAlreadyPersisted(): void
    {
        $rawData = $this->buildRawEndurainActivity();
        $rawStreams = $this->buildRawEndurainStreams();

        $this->endurain->expects($this->once())->method('getActivity')->willReturn($rawData);
        $this->endurain->expects($this->once())->method('getAllActivityStreams')->willReturn($rawStreams);
        $this->activityRepository->expects($this->once())->method('exists')->willReturn(false);

        $this->activityStreamRepository
            ->expects($this->exactly(2))
            ->method('hasOneForActivityAndStreamType')
            ->willReturn(true);

        $this->activityStreamRepository
            ->expects($this->never())
            ->method('add');

        $this->activityRepository
            ->expects($this->once())
            ->method('markActivityStreamsAsImported');

        $output = new SpyOutput();
        $this->handler->handle(new ImportEndurainActivity(
            output: $output,
            endurainActivityId: 1,
        ));
    }

    public function testHandleGracefullyHandles404OnStreamsWithoutPersistingAnyStreams(): void
    {
        $rawData = $this->buildRawEndurainActivity();

        $this->endurain->expects($this->once())->method('getActivity')->willReturn($rawData);
        $this->endurain
            ->expects($this->once())
            ->method('getAllActivityStreams')
            ->willThrowException(new ClientException(
                'Not Found',
                new Request('GET', 'api/v1/activities_streams/activity_id/1/all'),
                new Response(404),
            ));

        $this->activityRepository->expects($this->once())->method('exists')->willReturn(false);

        $this->activityStreamRepository
            ->expects($this->never())
            ->method('add');

        $this->activityRepository
            ->expects($this->never())
            ->method('markActivityStreamsAsImported');

        $this->activityRepository
            ->expects($this->once())
            ->method('add')
            ->with($this->callback(function (ActivityWithRawData $activityWithRawData): bool {
                // No streams available, so no polyline could be re-encoded.
                $this->assertNull($activityWithRawData->getActivity()->getEncodedPolyline());

                return true;
            }));

        $output = new SpyOutput();
        $this->handler->handle(new ImportEndurainActivity(
            output: $output,
            endurainActivityId: 1,
        ));
    }

    public function testHandleUpdatesExistingActivity(): void
    {
        $rawData = $this->buildRawEndurainActivity();
        $rawStreams = $this->buildRawEndurainStreams();

        $this->endurain
            ->expects($this->once())
            ->method('getActivity')
            ->with(1)
            ->willReturn($rawData);

        $this->endurain
            ->method('getAllActivityStreams')
            ->willReturn($rawStreams);

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

        $this->activityStreamRepository
            ->expects($this->exactly(2))
            ->method('hasOneForActivityAndStreamType')
            ->willReturn(false);

        $this->activityStreamRepository
            ->expects($this->exactly(2))
            ->method('add');

        $this->activityRepository
            ->expects($this->once())
            ->method('markActivityStreamsAsImported');

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

    /**
     * @return array<mixed>
     */
    private function buildRawEndurainStreams(): array
    {
        return [
            [
                'id' => 1,
                'activity_id' => 1,
                'stream_type' => 1,
                'stream_waypoints' => [
                    ['time' => '2026-06-22T19:11:56', 'hr' => 105],
                    ['time' => '2026-06-22T19:11:57', 'hr' => 106],
                ],
                'strava_activity_stream_id' => null,
                'hr_zone_percentages' => null,
            ],
            [
                'id' => 2,
                'activity_id' => 1,
                'stream_type' => 7,
                'stream_waypoints' => [
                    ['time' => '2026-06-22T19:11:56', 'lat' => 41.1, 'lon' => 2.1],
                    ['time' => '2026-06-22T19:11:57', 'lat' => 41.2, 'lon' => 2.2],
                ],
            ],
        ];
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->endurain = $this->createMock(Endurain::class);
        $this->activityRepository = $this->createMock(ActivityRepository::class);
        $this->activityStreamRepository = $this->createMock(ActivityStreamRepository::class);

        $this->handler = new ImportEndurainActivityCommandHandler(
            endurain: $this->endurain,
            activityRepository: $this->activityRepository,
            activityStreamRepository: $this->activityStreamRepository,
            endurainStreamParser: new EndurainStreamParser(),
            clock: PausedClock::fromString('2026-07-11 12:00:00'),
        );
    }
}
