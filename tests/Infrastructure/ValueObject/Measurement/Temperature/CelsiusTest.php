<?php

namespace App\Tests\Infrastructure\ValueObject\Measurement\Temperature;

use App\Infrastructure\ValueObject\Measurement\Temperature\Celsius;
use App\Infrastructure\ValueObject\Measurement\Temperature\Fahrenheit;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;
use PHPUnit\Framework\TestCase;

class CelsiusTest extends TestCase
{
    public function testToImperial(): void
    {
        $this->assertEquals(
            Fahrenheit::from(32),
            Celsius::zero()->toImperial()
        );
    }

    public function testToUnitSystemMetricReturnsItself(): void
    {
        $this->assertEquals(
            Celsius::from(20),
            Celsius::from(20)->toUnitSystem(UnitSystem::METRIC)
        );
    }

    public function testToUnitSystemImperialConvertsToFahrenheit(): void
    {
        $this->assertEquals(
            Fahrenheit::from(68),
            Celsius::from(20)->toUnitSystem(UnitSystem::IMPERIAL)
        );
    }
}
