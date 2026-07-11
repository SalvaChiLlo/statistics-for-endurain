<?php

declare(strict_types=1);

namespace App\Application\Import\EndurainImport\DetectEndurainActivityChanges;

use App\Domain\Activity\ActivityIds;

/**
 * The result of diffing the remote Endurain activity id list against the
 * locally imported Endurain activity id list.
 */
final readonly class EndurainActivityIdDiff
{
    private function __construct(
        private ActivityIds $newActivityIds,
        private ActivityIds $activityIdsToMarkForDeletion,
    ) {
    }

    public static function create(
        ActivityIds $newActivityIds,
        ActivityIds $activityIdsToMarkForDeletion,
    ): self {
        return new self(
            newActivityIds: $newActivityIds,
            activityIdsToMarkForDeletion: $activityIdsToMarkForDeletion,
        );
    }

    /**
     * Remote activity ids that are not yet imported locally. These are candidates for import.
     */
    public function getNewActivityIds(): ActivityIds
    {
        return $this->newActivityIds;
    }

    /**
     * Locally imported activity ids that are no longer present remotely. These are
     * candidates to be marked for deletion via ActivityRepository::markActivitiesForDeletion().
     *
     * This diff does not perform that write itself, nor does it apply any safety guard
     * against deleting everything (e.g. on a config/connectivity mistake). That decision
     * is left to the caller.
     */
    public function getActivityIdsToMarkForDeletion(): ActivityIds
    {
        return $this->activityIdsToMarkForDeletion;
    }
}
