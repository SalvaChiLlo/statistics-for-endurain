<?php

declare(strict_types=1);

namespace App\Domain\Activity;

/**
 * A DB-level copy (see the "migrate from statistics-for-strava" command)
 * doesn't rule out future overlap: e.g. an activity migrated from an old
 * Strava-backed install could later also show up through the ongoing
 * Endurain sync (manual file upload, re-sync, etc.), without a shared,
 * reliable external id to de-dupe on (file-uploaded Endurain activities in
 * particular have no Strava activity id at all).
 *
 * This is a fuzzy safety net for that scenario: it flags an incoming
 * activity as a likely duplicate of an already-stored one purely by
 * physical proximity (start time, distance, duration), regardless of
 * import source or external id.
 */
interface DuplicateActivityDetector
{
    /**
     * Returns the existing activity this candidate looks like a duplicate
     * of, or null if no likely duplicate was found.
     */
    public function findLikelyDuplicate(Activity $candidate): ?Activity;
}
