<?php

declare(strict_types=1);

namespace App\Domain\Endurain;

use App\Infrastructure\ValueObject\Measurement\Velocity\KmPerHour;
use App\Infrastructure\ValueObject\Measurement\Velocity\MetersPerSecond;
use App\Infrastructure\ValueObject\Measurement\Velocity\SecPerKm;

/**
 * Converts Endurain's raw speed/pace representation into this codebase's
 * internal Velocity value objects.
 *
 * Endurain's Activity API sends `average_speed` and `max_speed` as plain
 * JSON floats in meters/second, and `pace` as a plain JSON float in
 * seconds/meter. These are pure conversions with no HTTP/DB dependencies.
 *
 * Precision note: conversion delegates to the existing Velocity value
 * objects (MetersPerSecond::toKmPerHour(), and the sec/km scaling used
 * here), which already round to 3 decimal places. That is the same
 * precision the existing Strava import pipeline accepts for speed data
 * (see Activity::createFromRawStravaData()), so Endurain-sourced values
 * are bounded to the same, already-accepted precision loss: any magnitude
 * change smaller than 0.0005 in the target unit rounds to zero.
 */
final class EndurainSpeedConverter
{
    /**
     * Converts Endurain's `average_speed`/`max_speed` (meters/second) to
     * the internal KmPerHour VO. Formula: km/h = m/s * 3.6.
     */
    public static function toKmPerHour(float $metersPerSecond): KmPerHour
    {
        self::guardIsFiniteAndNonNegative($metersPerSecond, 'average_speed/max_speed');

        return MetersPerSecond::from($metersPerSecond)->toKmPerHour();
    }

    /**
     * Converts Endurain's `pace` (seconds/meter) to the internal SecPerKm
     * pace VO. Formula: sec/km = sec/m * 1000.
     */
    public static function toPace(float $secondsPerMeter): SecPerKm
    {
        self::guardIsFiniteAndNonNegative($secondsPerMeter, 'pace');

        if (0.0 === $secondsPerMeter) {
            return SecPerKm::zero();
        }

        return SecPerKm::from(round($secondsPerMeter * 1000, 3));
    }

    private static function guardIsFiniteAndNonNegative(float $value, string $fieldName): void
    {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException(sprintf('Endurain "%s" must be a finite number, got a non-finite value (NAN or INF).', $fieldName));
        }

        if ($value < 0.0) {
            throw new \InvalidArgumentException(sprintf('Endurain "%s" must not be negative, got "%s".', $fieldName, $value));
        }
    }
}
