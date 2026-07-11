<?php

declare(strict_types=1);

namespace App\Domain\Activity;

final readonly class ActivityRepositoryBasedDuplicateActivityDetector implements DuplicateActivityDetector
{
    /**
     * Two activities starting more than 5 minutes apart are never
     * considered duplicates, regardless of how similar distance/duration
     * are: this is meant to catch the same physical workout being present
     * twice (e.g. once migrated from Strava, once re-synced through
     * Endurain), not two separate, similar workouts.
     */
    private const int START_TIME_TOLERANCE_IN_SECONDS = 300;

    /**
     * Distance and moving time need to be within 5% of each other. This is
     * deliberately generous: different recording devices/apps round and
     * smooth GPS tracks differently, so exact matches are not expected.
     */
    private const float DISTANCE_TOLERANCE_PERCENTAGE = 0.05;
    private const float DURATION_TOLERANCE_PERCENTAGE = 0.05;

    public function __construct(
        private ActivityRepository $activityRepository,
    ) {
    }

    public function findLikelyDuplicate(Activity $candidate): ?Activity
    {
        foreach ($this->activityRepository->findAll() as $existingActivity) {
            if ((string) $existingActivity->getId() === (string) $candidate->getId()) {
                continue;
            }

            if ($this->isLikelyDuplicate($candidate, $existingActivity)) {
                return $existingActivity;
            }
        }

        return null;
    }

    private function isLikelyDuplicate(Activity $candidate, Activity $existingActivity): bool
    {
        $startTimeDifferenceInSeconds = abs(
            $candidate->getStartDate()->getTimestamp() - $existingActivity->getStartDate()->getTimestamp()
        );
        if ($startTimeDifferenceInSeconds > self::START_TIME_TOLERANCE_IN_SECONDS) {
            return false;
        }

        if (!$this->isWithinPercentageTolerance(
            $candidate->getDistance()->toMeter()->toFloat(),
            $existingActivity->getDistance()->toMeter()->toFloat(),
            self::DISTANCE_TOLERANCE_PERCENTAGE,
        )) {
            return false;
        }

        return $this->isWithinPercentageTolerance(
            (float) $candidate->getMovingTimeInSeconds(),
            (float) $existingActivity->getMovingTimeInSeconds(),
            self::DURATION_TOLERANCE_PERCENTAGE,
        );
    }

    private function isWithinPercentageTolerance(float $a, float $b, float $tolerancePercentage): bool
    {
        $reference = max($a, $b);
        if (0.0 === $reference) {
            // Both zero: treat as matching rather than dividing by zero.
            return true;
        }

        return abs($a - $b) / $reference <= $tolerancePercentage;
    }
}
