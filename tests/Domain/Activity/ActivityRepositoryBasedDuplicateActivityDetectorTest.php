<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity;

use App\Domain\Activity\Activities;
use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityRepositoryBasedDuplicateActivityDetector;
use App\Infrastructure\ValueObject\Measurement\Length\Kilometer;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

class ActivityRepositoryBasedDuplicateActivityDetectorTest extends TestCase
{
    public function testFindsLikelyDuplicateWithinTolerance(): void
    {
        $existing = ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('existing'))
            ->withStartDateTime(SerializableDateTime::fromString('2026-06-22 19:11:00'))
            ->withDistance(Kilometer::from(10.0))
            ->withMovingTimeInSeconds(1800)
            ->build();

        $candidate = ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('candidate'))
            // 2 minutes later, distance/duration within 5%.
            ->withStartDateTime(SerializableDateTime::fromString('2026-06-22 19:13:00'))
            ->withDistance(Kilometer::from(10.2))
            ->withMovingTimeInSeconds(1830)
            ->build();

        $detector = new ActivityRepositoryBasedDuplicateActivityDetector($this->activityRepositoryReturning($existing));

        $this->assertSame($existing, $detector->findLikelyDuplicate($candidate));
    }

    public function testDoesNotFlagActivitiesStartingTooFarApart(): void
    {
        $existing = ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('existing'))
            ->withStartDateTime(SerializableDateTime::fromString('2026-06-22 19:11:00'))
            ->withDistance(Kilometer::from(10.0))
            ->withMovingTimeInSeconds(1800)
            ->build();

        $candidate = ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('candidate'))
            // 20 minutes later: same distance/duration but a different workout.
            ->withStartDateTime(SerializableDateTime::fromString('2026-06-22 19:31:00'))
            ->withDistance(Kilometer::from(10.0))
            ->withMovingTimeInSeconds(1800)
            ->build();

        $detector = new ActivityRepositoryBasedDuplicateActivityDetector($this->activityRepositoryReturning($existing));

        $this->assertNull($detector->findLikelyDuplicate($candidate));
    }

    public function testDoesNotFlagActivitiesWithDifferentDistanceOrDuration(): void
    {
        $existing = ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('existing'))
            ->withStartDateTime(SerializableDateTime::fromString('2026-06-22 19:11:00'))
            ->withDistance(Kilometer::from(10.0))
            ->withMovingTimeInSeconds(1800)
            ->build();

        $candidate = ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('candidate'))
            ->withStartDateTime(SerializableDateTime::fromString('2026-06-22 19:11:00'))
            // Far outside the 5% distance tolerance.
            ->withDistance(Kilometer::from(15.0))
            ->withMovingTimeInSeconds(1800)
            ->build();

        $detector = new ActivityRepositoryBasedDuplicateActivityDetector($this->activityRepositoryReturning($existing));

        $this->assertNull($detector->findLikelyDuplicate($candidate));
    }

    public function testIgnoresItselfWhenActivityIdMatches(): void
    {
        $activityId = ActivityId::fromUnprefixed('same');
        $existing = ActivityBuilder::fromDefaults()
            ->withActivityId($activityId)
            ->withStartDateTime(SerializableDateTime::fromString('2026-06-22 19:11:00'))
            ->withDistance(Kilometer::from(10.0))
            ->withMovingTimeInSeconds(1800)
            ->build();

        $candidate = ActivityBuilder::fromDefaults()
            ->withActivityId($activityId)
            ->withStartDateTime(SerializableDateTime::fromString('2026-06-22 19:11:00'))
            ->withDistance(Kilometer::from(10.0))
            ->withMovingTimeInSeconds(1800)
            ->build();

        $detector = new ActivityRepositoryBasedDuplicateActivityDetector($this->activityRepositoryReturning($existing));

        $this->assertNull($detector->findLikelyDuplicate($candidate));
    }

    private function activityRepositoryReturning(Activity ...$activities): ActivityRepository
    {
        $repository = $this->createMock(ActivityRepository::class);
        $repository->expects($this->once())->method('findAll')->willReturn(Activities::fromArray($activities));

        return $repository;
    }
}
