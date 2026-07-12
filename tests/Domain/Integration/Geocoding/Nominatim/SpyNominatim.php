<?php

declare(strict_types=1);

namespace App\Tests\Domain\Integration\Geocoding\Nominatim;

use App\Domain\Activity\Route\RouteGeography;
use App\Domain\Integration\Geocoding\Nominatim\Nominatim;
use App\Infrastructure\ValueObject\Geography\Coordinate;

class SpyNominatim implements Nominatim
{
    public function reverseGeocode(Coordinate $coordinate): array
    {
        return [
            'country_code' => 'be',
            'state' => 'West Vlaanderen',
            // Mirrors LiveNominatim::reverseGeocode(), which always sets this
            // flag on a successful lookup so callers never re-geocode an
            // already-geocoded activity.
            RouteGeography::IS_REVERSE_GEOCODED => true,
        ];
    }
}
