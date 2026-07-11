<?php

declare(strict_types=1);

namespace App\Tests\Domain\Endurain;

use App\Domain\Endurain\EndurainSpeedConverter;
use App\Infrastructure\ValueObject\Measurement\Velocity\KmPerHour;
use App\Infrastructure\ValueObject\Measurement\Velocity\SecPerKm;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class EndurainSpeedConverterTest extends TestCase
{
    #[TestWith(data: [4.656, 16.762], name: 'confirmed real payload: 18.7km ride in 4024s')]
    #[TestWith(data: [1.0, 3.6], name: 'exactly 1 m/s')]
    #[TestWith(data: [10.0, 36.0], name: '10 m/s')]
    public function testToKmPerHourForTypicalValues(float $metersPerSecond, float $expectedKmPerHour): void
    {
        $this->assertEquals(
            KmPerHour::from($expectedKmPerHour),
            EndurainSpeedConverter::toKmPerHour($metersPerSecond),
        );
    }

    public function testToKmPerHourForZeroSpeed(): void
    {
        $this->assertEquals(
            KmPerHour::zero(),
            EndurainSpeedConverter::toKmPerHour(0.0),
        );
    }

    public function testToKmPerHourForVerySmallSpeedRoundsToZero(): void
    {
        // Anything below 0.0005 km/h (~0.000139 m/s) rounds to zero at the
        // 3-decimal precision this codebase's Velocity VOs already use.
        // This is a deliberate, bounded precision loss, not a bug.
        $this->assertEquals(
            KmPerHour::zero(),
            EndurainSpeedConverter::toKmPerHour(0.0000001),
        );
    }

    public function testToKmPerHourForVeryLargeSpeedDoesNotProduceNonsense(): void
    {
        // Far beyond any physically plausible activity, but float64 has
        // plenty of precision left at this magnitude (nowhere near the
        // 2^53 integer-precision ceiling), so the conversion stays exact
        // to the 3-decimal rounding the VO applies.
        $this->assertEquals(
            KmPerHour::from(3_600_000.0),
            EndurainSpeedConverter::toKmPerHour(1_000_000.0),
        );
    }

    public function testToKmPerHourRejectsNonFiniteInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EndurainSpeedConverter::toKmPerHour(NAN);
    }

    public function testToKmPerHourRejectsNegativeInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EndurainSpeedConverter::toKmPerHour(-1.0);
    }

    #[TestWith(data: [0.2147712905, 214.771], name: 'confirmed real payload pace')]
    #[TestWith(data: [0.3, 300.0], name: '0.3 sec/meter')]
    public function testToPaceForTypicalValues(float $secondsPerMeter, float $expectedSecPerKm): void
    {
        $this->assertEquals(
            SecPerKm::from($expectedSecPerKm),
            EndurainSpeedConverter::toPace($secondsPerMeter),
        );
    }

    public function testToPaceForZeroPace(): void
    {
        $this->assertEquals(
            SecPerKm::zero(),
            EndurainSpeedConverter::toPace(0.0),
        );
    }

    public function testToPaceForVerySmallPaceRoundsToZero(): void
    {
        $this->assertEquals(
            SecPerKm::zero(),
            EndurainSpeedConverter::toPace(0.0000001),
        );
    }

    public function testToPaceForVeryLargePaceDoesNotProduceNonsense(): void
    {
        $this->assertEquals(
            SecPerKm::from(1_000_000_000.0),
            EndurainSpeedConverter::toPace(1_000_000.0),
        );
    }

    public function testToPaceRejectsNonFiniteInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EndurainSpeedConverter::toPace(INF);
    }

    public function testToPaceRejectsNegativeInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EndurainSpeedConverter::toPace(-0.1);
    }
}
