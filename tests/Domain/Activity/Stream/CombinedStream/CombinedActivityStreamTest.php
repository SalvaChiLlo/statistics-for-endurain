<?php

namespace App\Tests\Domain\Activity\Stream\CombinedStream;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\Stream\CombinedStream\CombinedActivityStream;
use App\Domain\Activity\Stream\CombinedStream\CombinedStreamType;
use App\Domain\Activity\Stream\CombinedStream\CombinedStreamTypes;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;
use PHPUnit\Framework\TestCase;

class CombinedActivityStreamTest extends TestCase
{
    public function testGetters(): void
    {
        $stream = CombinedActivityStream::fromState(
            activityId: ActivityId::fromUnprefixed(1),
            unitSystem: UnitSystem::METRIC,
            streamTypes: CombinedStreamTypes::fromArray([CombinedStreamType::VELOCITY]),
            data: [],
            maxYAxisValue: 300,
        );

        $this->assertEmpty($stream->getTimes());
        $this->assertEmpty($stream->getDistances());
        $this->assertEmpty($stream->getCoordinates());

        $stream = CombinedActivityStream::fromState(
            activityId: ActivityId::fromUnprefixed(1),
            unitSystem: UnitSystem::METRIC,
            streamTypes: CombinedStreamTypes::fromArray([
                CombinedStreamType::TIME,
                CombinedStreamType::DISTANCE,
                CombinedStreamType::LAT_LNG,
            ]),
            data: [[1, 2, 3], [3, 2, 1]],
            maxYAxisValue: 300,
        );

        $this->assertEquals(
            [1, 3],
            $stream->getTimes()
        );
        $this->assertEquals(
            [2, 2],
            $stream->getDistances()
        );
        $this->assertEquals(
            [3, 1],
            $stream->getCoordinates()
        );
    }

    public function testGetCoordinatesWithMetricsReturnsEmptyArrayWhenNoLatLngStream(): void
    {
        $stream = CombinedActivityStream::fromState(
            activityId: ActivityId::fromUnprefixed(1),
            unitSystem: UnitSystem::METRIC,
            streamTypes: CombinedStreamTypes::fromArray([CombinedStreamType::VELOCITY]),
            data: [[10], [20]],
            maxYAxisValue: 300,
        );

        $this->assertSame([], $stream->getCoordinatesWithMetrics());
    }

    public function testGetCoordinatesWithMetricsKeepsRowsAlignedWhenCoordinateIsMissing(): void
    {
        $stream = CombinedActivityStream::fromState(
            activityId: ActivityId::fromUnprefixed(1),
            unitSystem: UnitSystem::METRIC,
            streamTypes: CombinedStreamTypes::fromArray([
                CombinedStreamType::LAT_LNG,
                CombinedStreamType::VELOCITY,
                CombinedStreamType::HEART_RATE,
                CombinedStreamType::ALTITUDE,
            ]),
            data: [
                [[51.1, 3.1], 10.0, 140, 5.0],
                // This row has no coordinate (e.g. lost GPS fix). getCoordinates() would drop it and shift
                // every following coordinate's index, but getCoordinatesWithMetrics() must skip it entirely
                // (coordinate + metrics together) rather than pairing the next coordinate with this row's metrics.
                [null, 15.0, 150, 6.0],
                [[51.3, 3.3], 20.0, 160, 7.0],
            ],
            maxYAxisValue: 300,
        );

        $this->assertSame(
            [
                ['lat' => 51.1, 'lng' => 3.1, 'speed' => 10.0, 'heartrate' => 140.0, 'cadence' => null, 'elevation' => 5.0, 'temperature' => null],
                ['lat' => 51.3, 'lng' => 3.3, 'speed' => 20.0, 'heartrate' => 160.0, 'cadence' => null, 'elevation' => 7.0, 'temperature' => null],
            ],
            $stream->getCoordinatesWithMetrics()
        );
    }

    public function testGetCoordinatesWithMetricsSkipsRowsWithMalformedCoordinate(): void
    {
        $stream = CombinedActivityStream::fromState(
            activityId: ActivityId::fromUnprefixed(1),
            unitSystem: UnitSystem::METRIC,
            streamTypes: CombinedStreamTypes::fromArray([
                CombinedStreamType::LAT_LNG,
                CombinedStreamType::VELOCITY,
            ]),
            data: [
                [[51.1, 3.1], 10.0],
                // A coordinate that is an array but doesn't have exactly [lat, lng] should be skipped too,
                // not just a missing/null coordinate.
                [[51.2], 15.0],
                [[51.3, 3.3, 0.0], 20.0],
                [[51.4, 3.4], 25.0],
            ],
            maxYAxisValue: 300,
        );

        $this->assertSame(
            [
                ['lat' => 51.1, 'lng' => 3.1, 'speed' => 10.0, 'heartrate' => null, 'cadence' => null, 'elevation' => null, 'temperature' => null],
                ['lat' => 51.4, 'lng' => 3.4, 'speed' => 25.0, 'heartrate' => null, 'cadence' => null, 'elevation' => null, 'temperature' => null],
            ],
            $stream->getCoordinatesWithMetrics()
        );
    }

    public function testGetCoordinatesWithMetricsFallsBackToStepsPerMinuteForCadence(): void
    {
        $stream = CombinedActivityStream::fromState(
            activityId: ActivityId::fromUnprefixed(1),
            unitSystem: UnitSystem::METRIC,
            streamTypes: CombinedStreamTypes::fromArray([
                CombinedStreamType::LAT_LNG,
                CombinedStreamType::STEPS_PER_MINUTE,
            ]),
            data: [
                [[51.1, 3.1], 170],
            ],
            maxYAxisValue: 300,
        );

        $this->assertSame(
            [
                ['lat' => 51.1, 'lng' => 3.1, 'speed' => null, 'heartrate' => null, 'cadence' => 170.0, 'elevation' => null, 'temperature' => null],
            ],
            $stream->getCoordinatesWithMetrics()
        );
    }

    public function testGetCoordinatesWithMetricsIncludesTemperature(): void
    {
        $stream = CombinedActivityStream::fromState(
            activityId: ActivityId::fromUnprefixed(1),
            unitSystem: UnitSystem::METRIC,
            streamTypes: CombinedStreamTypes::fromArray([
                CombinedStreamType::LAT_LNG,
                CombinedStreamType::VELOCITY,
                CombinedStreamType::TEMP,
            ]),
            data: [
                [[51.1, 3.1], 10.0, 18.5],
            ],
            maxYAxisValue: 300,
        );

        $this->assertSame(
            [
                ['lat' => 51.1, 'lng' => 3.1, 'speed' => 10.0, 'heartrate' => null, 'cadence' => null, 'elevation' => null, 'temperature' => 18.5],
            ],
            $stream->getCoordinatesWithMetrics()
        );
    }
}
