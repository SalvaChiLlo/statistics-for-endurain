<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Gate;

use App\Domain\Activity\ActivityIdRepository;
use App\Domain\Import\ImportMode;
use App\Domain\Strava\InsufficientStravaAccessTokenScopes;
use App\Domain\Strava\InvalidStravaAccessToken;
use App\Domain\Strava\Strava;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 90)]
final class ValidStravaRefreshTokenGate extends ConditionalRedirectGate
{
    public function __construct(
        private readonly ImportMode $importMode,
        private readonly Strava $strava,
        private readonly ActivityIdRepository $activityIdRepository,
    ) {
    }

    protected function shouldGuard(): bool
    {
        if (!$this->importMode->isStravaApi()) {
            return false;
        }

        if ($this->activityIdRepository->hasImportedFromStravaApi()) {
            return false;
        }

        try {
            $this->strava->verifyAccessToken();

            return false;
        } catch (InvalidStravaAccessToken|InsufficientStravaAccessTokenScopes) {
            return true;
        }
    }

    protected function allowedPaths(): array
    {
        return [];
    }

    protected function redirectTo(): string
    {
        return '/strava-oauth';
    }
}
