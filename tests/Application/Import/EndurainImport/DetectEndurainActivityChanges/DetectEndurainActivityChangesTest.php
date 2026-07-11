<?php

namespace App\Tests\Application\Import\EndurainImport\DetectEndurainActivityChanges;

use App\Application\Import\EndurainImport\DetectEndurainActivityChanges\DetectEndurainActivityChanges;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityIdRepository;
use App\Domain\Activity\ActivityIds;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DetectEndurainActivityChangesTest extends TestCase
{
    private MockObject $activityIdRepository;
    private DetectEndurainActivityChanges $detectEndurainActivityChanges;

    public function testNewRemoteActivityIsDetected(): void
    {
        $this->activityIdRepository
            ->expects($this->once())
            ->method('findAllImportedFromEndurainApi')
            ->willReturn(ActivityIds::empty());

        $diff = $this->detectEndurainActivityChanges->diff([
            ['id' => 1],
        ]);

        $this->assertEquals(
            ActivityIds::fromArray([ActivityId::fromUnprefixed('endurain-1')]),
            $diff->getNewActivityIds()
        );
        $this->assertTrue($diff->getActivityIdsToMarkForDeletion()->isEmpty());
    }

    public function testLocalActivityMissingFromRemoteIsMarkedForDeletion(): void
    {
        $this->activityIdRepository
            ->expects($this->once())
            ->method('findAllImportedFromEndurainApi')
            ->willReturn(ActivityIds::fromArray([
                ActivityId::fromUnprefixed('endurain-1'),
                ActivityId::fromUnprefixed('endurain-2'),
            ]));

        $diff = $this->detectEndurainActivityChanges->diff([
            ['id' => 1],
        ]);

        $this->assertTrue($diff->getNewActivityIds()->isEmpty());
        $this->assertEquals(
            ActivityIds::fromArray([ActivityId::fromUnprefixed('endurain-2')]),
            $diff->getActivityIdsToMarkForDeletion()
        );
    }

    public function testUnchangedListProducesEmptyDiff(): void
    {
        $this->activityIdRepository
            ->expects($this->once())
            ->method('findAllImportedFromEndurainApi')
            ->willReturn(ActivityIds::fromArray([
                ActivityId::fromUnprefixed('endurain-1'),
                ActivityId::fromUnprefixed('endurain-2'),
            ]));

        $diff = $this->detectEndurainActivityChanges->diff([
            ['id' => 1],
            ['id' => 2],
        ]);

        $this->assertTrue($diff->getNewActivityIds()->isEmpty());
        $this->assertTrue($diff->getActivityIdsToMarkForDeletion()->isEmpty());
    }

    public function testRawRemoteIdIsPrefixedWithEndurainBeforeComparison(): void
    {
        // Local storage holds the "endurain-" prefixed id (see Activity::createFromRawEndurainData()).
        // A raw remote id of "5" must be recognised as matching the locally stored "endurain-5",
        // not as a brand-new activity, and must not accidentally match an unprefixed local "5".
        $this->activityIdRepository
            ->expects($this->once())
            ->method('findAllImportedFromEndurainApi')
            ->willReturn(ActivityIds::fromArray([
                ActivityId::fromUnprefixed('endurain-5'),
            ]));

        $diff = $this->detectEndurainActivityChanges->diff([
            ['id' => 5],
        ]);

        $this->assertTrue($diff->getNewActivityIds()->isEmpty());
        $this->assertTrue($diff->getActivityIdsToMarkForDeletion()->isEmpty());
    }

    public function testCombinedNewAndDeletedActivities(): void
    {
        $this->activityIdRepository
            ->expects($this->once())
            ->method('findAllImportedFromEndurainApi')
            ->willReturn(ActivityIds::fromArray([
                ActivityId::fromUnprefixed('endurain-1'),
                ActivityId::fromUnprefixed('endurain-2'),
            ]));

        $diff = $this->detectEndurainActivityChanges->diff([
            ['id' => 2],
            ['id' => 3],
        ]);

        $this->assertEquals(
            ActivityIds::fromArray([ActivityId::fromUnprefixed('endurain-3')]),
            $diff->getNewActivityIds()
        );
        $this->assertEquals(
            ActivityIds::fromArray([ActivityId::fromUnprefixed('endurain-1')]),
            $diff->getActivityIdsToMarkForDeletion()
        );
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->activityIdRepository = $this->createMock(ActivityIdRepository::class);

        $this->detectEndurainActivityChanges = new DetectEndurainActivityChanges(
            activityIdRepository: $this->activityIdRepository,
        );
    }
}
