<?php

declare(strict_types=1);

namespace App\Application\Import\EndurainImport\DetectEndurainActivityChanges;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityIdRepository;
use App\Domain\Activity\ActivityIds;

/**
 * Diffs the current list of remote Endurain activities (as returned by
 * Endurain::getActivities()) against the locally imported Endurain
 * activities, to determine which remote activities are new (need import)
 * and which locally imported activities are no longer present remotely
 * (candidates to be marked for deletion).
 *
 * This is intentionally a pure, read-only diffing step: besides the
 * read-only lookup of locally imported activity ids, it performs no I/O.
 * It does not import activities, and it does not call
 * ActivityRepository::markActivitiesForDeletion() itself. Deciding when and
 * whether to act on the returned diff is left to the caller.
 */
final readonly class DetectEndurainActivityChanges
{
    public function __construct(
        private ActivityIdRepository $activityIdRepository,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $rawRemoteEndurainActivities Raw activities as returned
     *                                                                      by Endurain::getActivities(), each with Endurain's raw unprefixed integer 'id'
     */
    public function diff(array $rawRemoteEndurainActivities): EndurainActivityIdDiff
    {
        $remoteActivityIds = ActivityIds::fromArray(array_map(
            fn (array $rawActivity): ActivityId => ActivityId::fromUnprefixed('endurain-'.$rawActivity['id']),
            $rawRemoteEndurainActivities,
        ));

        $locallyImportedActivityIds = $this->activityIdRepository->findAllImportedFromEndurainApi();

        $newActivityIds = $remoteActivityIds->filter(
            fn (ActivityId $activityId): bool => !$locallyImportedActivityIds->has($activityId)
        );

        $activityIdsToMarkForDeletion = $locallyImportedActivityIds->filter(
            fn (ActivityId $activityId): bool => !$remoteActivityIds->has($activityId)
        );

        return EndurainActivityIdDiff::create(
            newActivityIds: $newActivityIds,
            activityIdsToMarkForDeletion: $activityIdsToMarkForDeletion,
        );
    }
}
