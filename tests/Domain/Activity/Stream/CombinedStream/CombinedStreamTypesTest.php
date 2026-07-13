<?php

namespace App\Tests\Domain\Activity\Stream\CombinedStream;

use App\Domain\Activity\ActivityType;
use App\Domain\Activity\Stream\CombinedStream\CombinedStreamType;
use App\Domain\Activity\Stream\CombinedStream\CombinedStreamTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CombinedStreamTypesTest extends TestCase
{
    #[DataProvider('activityTypeProvider')]
    public function testOthersForIncludesTempForAllActivityTypes(ActivityType $activityType): void
    {
        $this->assertTrue(
            CombinedStreamTypes::othersFor($activityType)->has(CombinedStreamType::TEMP)
        );
    }

    #[DataProvider('activityTypeProvider')]
    public function testOthersForHasTempAsFirstItemSoItRendersLastAfterReverse(ActivityType $activityType): void
    {
        $this->assertSame(
            CombinedStreamType::TEMP,
            CombinedStreamTypes::othersFor($activityType)->getFirst()
        );
    }

    /**
     * @return array<int, array{0: ActivityType}>
     */
    public static function activityTypeProvider(): array
    {
        return array_map(
            static fn (ActivityType $activityType): array => [$activityType],
            ActivityType::cases()
        );
    }
}
