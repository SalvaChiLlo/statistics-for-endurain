<?php

declare(strict_types=1);

namespace App\Tests\Domain\Endurain\Stream;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Endurain\Stream\EndurainStreamParser;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;
use App\Infrastructure\ValueObject\Geography\GeoMath;
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

    /**
     * Regression test for issue #45: real Endurain activities render as a
     * short straight line instead of the actual route. The original bug
     * hypothesis (buildStreamMap()'s exact-time-match alignment silently
     * nulling out almost the entire LAT_LNG stream) was investigated
     * against a real deployment and REFUTED - on a real ~724-waypoint
     * activity, every LAT_LNG waypoint survived exact-match alignment.
     * The actual bug was Polyline::simplify()'s default tolerance being
     * expressed in decimal-degree units but set far too large (0.4, i.e.
     * ~44km), so it collapsed any normally-sized real activity's route
     * down to its two endpoints regardless of alignment correctness.
     *
     * This test reproduces that failure mode end-to-end through the
     * actual parser, with a realistic waypoint count (~700, matching the
     * order of magnitude observed on a real activity) and a synthesized
     * (not real) wandering path whose curvature is on the same order of
     * magnitude as what was observed on real GPS data, so a correct fix
     * must keep meaningfully more than the two endpoints.
     */
    public function testRealisticLatLngStreamProducesAMultiPointPolylineNotJustTwoEndpoints(): void
    {
        $waypointCount = 700;
        $rawWaypoints = [];
        $startTime = new \DateTimeImmutable('2026-06-22T17:00:00');

        for ($i = 0; $i < $waypointCount; ++$i) {
            $t = $i / ($waypointCount - 1);
            // Fake, plausible-looking wandering path: overall drift plus a
            // wobble whose amplitude (~0.002-0.004 degrees) mirrors the
            // order of magnitude of real GPS coordinate spread observed
            // while investigating #45. Not real coordinates.
            $lat = 41.000000 + $t * 0.015 + 0.003 * sin($t * 8 * \M_PI);
            $lon = 2.000000 + $t * 0.020 + 0.0025 * cos($t * 6 * \M_PI);

            $rawWaypoints[] = [
                'time' => $startTime->modify(\sprintf('+%d seconds', $i))->format('Y-m-d\TH:i:s'),
                'lat' => round($lat, 6),
                'lon' => round($lon, 6),
            ];
        }

        $rawStreams = [
            [
                'stream_type' => 7, // lat+lon
                'stream_waypoints' => $rawWaypoints,
            ],
        ];

        $result = $this->parser->parse($rawStreams, $this->activityId, $this->createdOn);

        $latLng = $result->getStreams()->filterOnType(StreamType::LAT_LNG);
        $this->assertNotNull($latLng);
        // Alignment itself must preserve every waypoint (confirms the
        // exact-match hypothesis is NOT the bug: no unexpected nulls).
        $this->assertCount($waypointCount, $latLng->getData());
        $this->assertEmpty(array_filter($latLng->getData(), static fn (mixed $value): bool => null === $value));

        $polyline = $result->getPolyline();
        $this->assertNotNull($polyline);

        $decoded = EncodedPolyline::fromString($polyline)->decodeAndPairLatLng();

        // The bug produced exactly 2 points (start/end only) for any
        // realistically-sized route. A correct fix must keep meaningfully
        // more than that while still simplifying away truly redundant
        // points, so assert comfortably above the broken value without
        // pinning to an exact simplification count.
        $this->assertGreaterThan(10, count($decoded), 'Polyline collapsed to (near) a straight line - simplify() tolerance is too coarse for real GPS data.');

        // Second symptom, same root cause investigation (reported once the
        // polyline bug was already being fixed): "evolution"/over-time
        // charts had stopped rendering for Endurain activities, while
        // distribution/histogram charts kept working. That turned out to
        // be unrelated to alignment or simplify() - Endurain never
        // produced a StreamType::TIME stream at all (unlike every other
        // parser in this codebase), and CalculateCombinedStreams (which
        // evolution charts read from) unconditionally skips any activity
        // without one. Assert it's now present and fully populated.
        $timeStream = $result->getStreams()->filterOnType(StreamType::TIME);
        $this->assertNotNull($timeStream);
        $this->assertCount($waypointCount, $timeStream->getData());
        $this->assertEmpty(array_filter($timeStream->getData(), static fn (mixed $value): bool => null === $value));
        $this->assertSame(0, $timeStream->getData()[0]);
        $this->assertSame($waypointCount - 1, $timeStream->getData()[$waypointCount - 1]);

        $distanceStream = $result->getStreams()->filterOnType(StreamType::DISTANCE);
        $this->assertNotNull($distanceStream);
        $this->assertCount($waypointCount, $distanceStream->getData());
        // Cumulative distance must be non-decreasing and end up > 0 for a
        // route that actually moves.
        $this->assertSame(0.0, $distanceStream->getData()[0]);
        $this->assertGreaterThan(0.0, $distanceStream->getData()[$waypointCount - 1]);
    }

    public function testElapsedTimeStreamIsDerivedFromTheCanonicalTimeAxis(): void
    {
        $rawStreams = [
            [
                'stream_type' => 1, // heart rate
                'stream_waypoints' => [
                    ['time' => '2026-06-22T17:11:56', 'hr' => 105],
                    ['time' => '2026-06-22T17:11:57', 'hr' => 106],
                    ['time' => '2026-06-22T17:12:01', 'hr' => 107],
                ],
            ],
        ];

        $result = $this->parser->parse($rawStreams, $this->activityId, $this->createdOn);

        $timeStream = $result->getStreams()->filterOnType(StreamType::TIME);
        $this->assertNotNull($timeStream);
        $this->assertEquals([0, 1, 5], $timeStream->getData());
    }

    public function testDistanceStreamIsDerivedFromLatLngWaypointsWhenPresent(): void
    {
        $rawStreams = [
            [
                'stream_type' => 7, // lat+lon
                'stream_waypoints' => [
                    ['time' => '2026-06-22T17:11:56', 'lat' => 41.0000, 'lon' => 2.0000],
                    ['time' => '2026-06-22T17:11:57', 'lat' => 41.0010, 'lon' => 2.0000],
                    ['time' => '2026-06-22T17:11:58', 'lat' => 41.0020, 'lon' => 2.0000],
                ],
            ],
        ];

        $result = $this->parser->parse($rawStreams, $this->activityId, $this->createdOn);

        $distanceStream = $result->getStreams()->filterOnType(StreamType::DISTANCE);
        $this->assertNotNull($distanceStream);

        $delta1 = GeoMath::haversineDistance(41.0000, 2.0000, 41.0010, 2.0000);
        $delta2 = GeoMath::haversineDistance(41.0010, 2.0000, 41.0020, 2.0000);

        $this->assertSame([0.0, round($delta1, 2), round($delta1 + $delta2, 2)], $distanceStream->getData());
    }

    public function testNoDistanceStreamIsDerivedWhenThereIsNoLatLngData(): void
    {
        $rawStreams = [
            [
                'stream_type' => 1, // heart rate only, no coordinates
                'stream_waypoints' => [
                    ['time' => '2026-06-22T17:11:56', 'hr' => 105],
                ],
            ],
        ];

        $result = $this->parser->parse($rawStreams, $this->activityId, $this->createdOn);

        $this->assertNull($result->getStreams()->filterOnType(StreamType::DISTANCE));
        // TIME must still be derived even without coordinates.
        $this->assertNotNull($result->getStreams()->filterOnType(StreamType::TIME));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new EndurainStreamParser();
        $this->activityId = ActivityId::fromUnprefixed('endurain-1');
        $this->createdOn = SerializableDateTime::fromString('2026-06-22 20:00:00');
    }
}
