<?php

declare(strict_types=1);

namespace App\Domain\Activity\Route;

use App\Domain\Activity\Activity;
use App\Domain\Integration\Geocoding\Nominatim\CouldNotReverseGeocodeAddress;
use App\Domain\Integration\Geocoding\Nominatim\Nominatim;

/**
 * Reverse-geocodes an activity's route: resolves the starting coordinate's
 * country code (and other address details) through Nominatim, and works out
 * which countries the route's polyline passed through.
 *
 * Shared between both import pipelines - the file-based import
 * (App\Application\Import\FileImport\ImportActivityFiles\Pipeline\AnalyzeRouteGeography)
 * and the Endurain import (App\Application\Import\EndurainImport\ImportEndurainActivity\ImportEndurainActivityCommandHandler)
 * - so this logic (and its "only geocode once" guards) lives in exactly one
 * place instead of being duplicated per pipeline.
 */
final readonly class RouteGeographyReverseGeocoder
{
    public function __construct(
        private Nominatim $nominatim,
        private RouteGeographyAnalyzer $routeGeographyAnalyzer,
    ) {
    }

    public function analyze(Activity $activity): Activity
    {
        $sportType = $activity->getSportType();

        $routeGeography = $activity->getRouteGeography();
        if (!$routeGeography->isReversedGeocoded() && $activity->getStartingCoordinate()) {
            if ($sportType->supportsReverseGeocoding()) {
                try {
                    $routeGeography = $routeGeography->updateWith(
                        $this->nominatim->reverseGeocode($activity->getStartingCoordinate())
                    );
                } catch (CouldNotReverseGeocodeAddress) {
                }
            } elseif ($activity->isZwiftRide() && ($zwiftMap = $activity->getLeafletMap())) {
                $routeGeography = $routeGeography->updateWith([
                    'state' => $zwiftMap->getLabel(),
                ]);
            }
        }

        if (!$activity->getRouteGeography()->hasBeenAnalyzedForRouteGeography()
            && $sportType->supportsReverseGeocoding() && $activity->getEncodedPolyline()) {
            $routeGeography = $routeGeography->updateWith([
                RouteGeography::PASSED_TROUGH_COUNTRIES => $this->routeGeographyAnalyzer->analyzeForPolyline(
                    $activity->getEncodedPolyline()
                ),
            ]);
        }

        return $activity->withRouteGeography($routeGeography);
    }
}
