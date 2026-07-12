<?php

declare(strict_types=1);

namespace App\Application\Import\FileImport\ImportActivityFiles\Pipeline;

use App\Domain\Activity\Route\RouteGeographyReverseGeocoder;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 90)]
final readonly class AnalyzeRouteGeography implements ImportActivityFileStep
{
    public function __construct(
        private RouteGeographyReverseGeocoder $routeGeographyReverseGeocoder,
    ) {
    }

    public function process(ActivityImportContext $context): ActivityImportContext
    {
        $activity = $context->getActivity() ?? throw new \RuntimeException('Activity not set on $context');

        return $context->withActivity($this->routeGeographyReverseGeocoder->analyze($activity));
    }
}
