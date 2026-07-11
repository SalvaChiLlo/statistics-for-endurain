<?php

declare(strict_types=1);

namespace App\Tests\Domain\Endurain\Stream;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Endurain\Stream\EndurainStreamParser;
use App\Infrastructure\ValueObject\Geography\Polyline;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

class EndurainStreamParserTest extends TestCase
{
    private EndurainStreamParser $parser;
    private ActivityId $activityId;
    private SerializableDateTime $createdOn;

    public function testEmptyRawStreamsProduceNoStreamsAndNoPolyline(): void
    {
        $result = $this->parser->parse([], $this->activityId, $this->createdOn);

        $this->assertTrue($result->getStreams()->isEmpty());
        $this->assertNull($result->getPolyline());
    }

    public function testHeartRateAndAltitudeWithDifferentWaypointCountsAreAlignedOnAUnionTimeAxis(): void
    {
        $rawStreams = [
            [
                'stream_type' => 1, // heart rate
                'stream_waypoints' => [
                    ['time' => '2026-06-22T17:11:56', 'hr' => 105],
                    ['time' => '2026-06-22T17:11:57', 'hr' => 106],
                    ['time' => '2026-06-22T17:11:58', 'hr' => 107],
                ],
            ],
            [
                'stream_type' => 4, // altitude
                'stream_waypoints' => [
                    ['time' => '2026-06-22T17:11:56', 'ele' => 65.4],
                    ['time' => '2026-06-22T17:11:58', 'ele' => 66.0],
                ],
            ],
        ];

        $result = $this->parser->parse($rawStreams, $this->activityId, $this->createdOn);

        $heartRate = $result->getStreams()->filterOnType(StreamType::HEART_RATE);
        $altitude = $result->getStreams()->filterOnType(StreamType::ALTITUDE);

        $this->assertNotNull($heartRate);
        $this->assertNotNull($altitude);

        // Union axis: 17:11:56, 17:11:57, 17:11:58 - 3 canonical indices.
        $this->assertEquals([105, 106, 107], $heartRate->getData());
        // Altitude has no waypoint at 17:11:57 - that index must be null,
        // not skipped, keeping both streams the same length/index-aligned.
        $this->assertEquals([65.4, null, 66.0], $altitude->getData());
    }

    public function testTimestampPresentInOneStreamTypeButNotAnotherIsNullAligned(): void
    {
        // Explicit gap-handling case: velocity has a waypoint at a timestamp
        // heart rate does not report at all (e.g. a brief HR sensor dropout).
        $rawStreams = [
            [
                'stream_type' => 1, // heart rate
                'stream_waypoints' => [
                    ['time' => '2026-06-22T17:11:56', 'hr' => 105],
                    ['time' => '2026-06-22T17:11:58', 'hr' => 107],
                ],
            ],
            [
                'stream_type' => 5, // velocity
                'stream_waypoints' => [
                    ['time' => '2026-06-22T17:11:56', 'vel' => 3.1],
                    ['time' => '2026-06-22T17:11:57', 'vel' => 3.4],
                    ['time' => '2026-06-22T17:11:58', 'vel' => 3.5],
                ],
            ],
        ];

        $result = $this->parser->parse($rawStreams, $this->activityId, $this->createdOn);

        $heartRate = $result->getStreams()->filterOnType(StreamType::HEART_RATE);
        $velocity = $result->getStreams()->filterOnType(StreamType::VELOCITY);

        $this->assertEquals([105, null, 107], $heartRate->getData());
        $this->assertEquals([3.1, 3.4, 3.5], $velocity->getData());
    }

    public function testUnconfirmedPowerKeyAbsentFromEveryWaypointOmitsTheStreamWithoutCrashing(): void
    {
        $rawStreams = [
            [
                'stream_type' => 2, // power, unconfirmed shape
                'stream_waypoints' => [
                    ['time' => '2026-06-22T17:11:56', 'unexpected_key' => 210],
                    ['time' => '2026-06-22T17:11:57', 'unexpected_key' => 215],
                ],
            ],
        ];

        $result = $this->parser->parse($rawStreams, $this->activityId, $this->createdOn);

        $this->assertNull($result->getStreams()->filterOnType(StreamType::WATTS));
    }

    public function testUnconfirmedCadenceKeyPartiallyPresentKeepsNullsForMissingWaypoints(): void
    {
        $rawStreams = [
            [
                'stream_type' => 3, // cadence, unconfirmed shape
                'stream_waypoints' => [
                    ['time' => '2026-06-22T17:11:56', 'cad' => 80],
                    // Missing "cad" key entirely on this waypoint - must not crash.
                    ['time' => '2026-06-22T17:11:57'],
                ],
            ],
        ];

        $result = $this->parser->parse($rawStreams, $this->activityId, $this->createdOn);

        $cadence = $result->getStreams()->filterOnType(StreamType::CADENCE);
        $this->assertNotNull($cadence);
        $this->assertEquals([80, null], $cadence->getData());
    }

    public function testPaceStreamTypeIsOmittedEntirely(): void
    {
        $rawStreams = [
            [
                'stream_type' => 6, // pace
                'stream_waypoints' => [
                    ['time' => '2026-06-22T17:11:56', 'pace' => 0.21],
                ],
            ],
        ];

        $result = $this->parser->parse($rawStreams, $this->activityId, $this->createdOn);

        $this->assertTrue($result->getStreams()->isEmpty());
    }

    public function testMovingAndGradeSmoothHaveNoEquivalentAndAreNeverProduced(): void
    {
        $rawStreams = [
            [
                'stream_type' => 1,
                'stream_waypoints' => [
                    ['time' => '2026-06-22T17:11:56', 'hr' => 105],
                ],
            ],
        ];

        $result = $this->parser->parse($rawStreams, $this->activityId, $this->createdOn);

        $this->assertNull($result->getStreams()->filterOnType(StreamType::MOVING));
        $this->assertNull($result->getStreams()->filterOnType(StreamType::GRADE));
    }

    public function testPolylineIsCorrectlyEncodedFromLatLngWaypoints(): void
    {
        $rawStreams = [
            [
                'stream_type' => 7, // lat+lon
                'stream_waypoints' => [
                    ['time' => '2026-06-22T17:11:56', 'lat' => 41.123456, 'lon' => 2.123456],
                    ['time' => '2026-06-22T17:11:57', 'lat' => 41.123556, 'lon' => 2.123556],
                    ['time' => '2026-06-22T17:11:58', 'lat' => 41.123656, 'lon' => 2.123656],
                ],
            ],
        ];

        $result = $this->parser->parse($rawStreams, $this->activityId, $this->createdOn);

        $expected = (string) Polyline::fromCoordinates([
            [41.123456, 2.123456],
            [41.123556, 2.123556],
            [41.123656, 2.123656],
        ])->simplify()->encode();

        $this->assertSame($expected, $result->getPolyline());

        $latLng = $result->getStreams()->filterOnType(StreamType::LAT_LNG);
        $this->assertNotNull($latLng);
        $this->assertEquals([
            [41.123456, 2.123456],
            [41.123556, 2.123556],
            [41.123656, 2.123656],
        ], $latLng->getData());
    }

    public function testMissingLatOrLonOnAWaypointIsNullNotACrash(): void
    {
        $rawStreams = [
            [
                'stream_type' => 7,
                'stream_waypoints' => [
                    ['time' => '2026-06-22T17:11:56', 'lat' => 41.1, 'lon' => 2.1],
                    ['time' => '2026-06-22T17:11:57', 'lat' => 41.2], // lon missing
                ],
            ],
        ];

        $result = $this->parser->parse($rawStreams, $this->activityId, $this->createdOn);

        $latLng = $result->getStreams()->filterOnType(StreamType::LAT_LNG);
        $this->assertEquals([[41.1, 2.1], null], $latLng->getData());

        // Only the valid coordinate contributes to the polyline.
        $expected = (string) Polyline::fromCoordinates([[41.1, 2.1]])->simplify()->encode();
        $this->assertSame($expected, $result->getPolyline());
    }

    public function testUnknownStreamTypeIsIgnored(): void
    {
        $rawStreams = [
            [
                'stream_type' => 99,
                'stream_waypoints' => [
                    ['time' => '2026-06-22T17:11:56', 'whatever' => 1],
                ],
            ],
        ];

        $result = $this->parser->parse($rawStreams, $this->activityId, $this->createdOn);

        $this->assertTrue($result->getStreams()->isEmpty());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new EndurainStreamParser();
        $this->activityId = ActivityId::fromUnprefixed('endurain-1');
        $this->createdOn = SerializableDateTime::fromString('2026-06-22 20:00:00');
    }
}
