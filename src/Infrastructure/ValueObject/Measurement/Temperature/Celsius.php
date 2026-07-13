<?php

declare(strict_types=1);

namespace App\Infrastructure\ValueObject\Measurement\Temperature;

use App\Infrastructure\ValueObject\Measurement\Metric;
use App\Infrastructure\ValueObject\Measurement\ProvideMeasurementUnit;
use App\Infrastructure\ValueObject\Measurement\Unit;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;

final readonly class Celsius implements Unit, Metric
{
    use ProvideMeasurementUnit;

    public function getSymbol(): string
    {
        return '°C';
    }

    public function toImperial(): Unit
    {
        return Fahrenheit::from(round(($this->value * (9 / 5)) + 32, 2));
    }

    public function toUnitSystem(UnitSystem $unitSystem): Celsius|Fahrenheit
    {
        if (UnitSystem::METRIC === $unitSystem) {
            return $this;
        }

        return Fahrenheit::from(round(($this->value * (9 / 5)) + 32, 2));
    }
}
