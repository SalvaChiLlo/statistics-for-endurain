<?php

declare(strict_types=1);

namespace App\Tests\Domain\Endurain;

use App\Domain\Activity\SportType\SportType;
use App\Domain\Endurain\EnduranActivityType;
use App\Domain\Endurain\EnduranActivityTypeTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EnduranActivityTypeTranslatorTest extends TestCase
{
    /**
     * Every Strava SportType must map to some EnduranActivityType without
     * throwing.
     */
    public function testEverySportTypeMapsToAnEnduranActivityType(): void
    {
        foreach (SportType::cases() as $sportType) {
            $enduranActivityType = EnduranActivityTypeTranslator::fromSportType($sportType);
            $this->assertInstanceOf(EnduranActivityType::class, $enduranActivityType);
        }
    }

    /**
     * Every Endurain activity_type code (1-47) must map to some SportType
     * without throwing.
     */
    public function testEveryEnduranActivityTypeMapsToASportType(): void
    {
        foreach (EnduranActivityType::cases() as $enduranActivityType) {
            $sportType = EnduranActivityTypeTranslator::toSportType($enduranActivityType);
            $this->assertInstanceOf(SportType::class, $sportType);
        }
    }

    public function testEnduranActivityTypeVocabularyCoversCodesOneTo47(): void
    {
        $values = array_map(fn (EnduranActivityType $type) => $type->value, EnduranActivityType::cases());
        sort($values);

        $this->assertSame(range(1, 47), $values);
    }

    /**
     * Round-trip: Strava -> Endurain -> Strava should be stable for sport
     * types that have a direct, unambiguous Endurain equivalent (i.e. no
     * documented fallback was needed in either direction).
     */
    public function testRoundTripIsStableForDirectlyMappedSportTypes(): void
    {
        $directlyMapped = [
            SportType::RIDE,
            SportType::MOUNTAIN_BIKE_RIDE,
            SportType::GRAVEL_RIDE,
            SportType::E_BIKE_RIDE,
            SportType::E_MOUNTAIN_BIKE_RIDE,
            SportType::VIRTUAL_RIDE,
            SportType::RUN,
            SportType::TRAIL_RUN,
            SportType::VIRTUAL_RUN,
            SportType::WALK,
            SportType::HIKE,
            SportType::KAYAKING,
            SportType::ROWING,
            SportType::STAND_UP_PADDLING,
            SportType::SURFING,
            SportType::WIND_SURF,
            SportType::ALPINE_SKI,
            SportType::NORDIC_SKI,
            SportType::ICE_SKATE,
            SportType::SNOWBOARD,
            SportType::SNOWSHOE,
            SportType::INLINE_SKATE,
            SportType::BADMINTON,
            SportType::PICKLE_BALL,
            SportType::RACQUET_BALL,
            SportType::SQUASH,
            SportType::TABLE_TENNIS,
            SportType::TENNIS,
            SportType::PADEL,
            SportType::CROSSFIT,
            SportType::WEIGHT_TRAINING,
            SportType::WORKOUT,
            SportType::HIIT,
            SportType::YOGA,
            SportType::SAIL,
            SportType::SOCCER,
        ];

        foreach ($directlyMapped as $sportType) {
            $enduranActivityType = EnduranActivityTypeTranslator::fromSportType($sportType);
            $this->assertSame(
                $sportType,
                EnduranActivityTypeTranslator::toSportType($enduranActivityType),
                sprintf('Expected round-trip of "%s" to be stable', $sportType->value)
            );
        }
    }

    /**
     * Documented fallback behavior: Strava sport types with no close
     * Endurain equivalent resolve to a documented, sensible bucket instead
     * of throwing. These are intentionally lossy in the Strava -> Endurain
     * direction.
     *
     * @return array<int, array{0: SportType, 1: EnduranActivityType}>
     */
    public static function unmappedSportTypeProvider(): array
    {
        return [
            [SportType::VELO_MOBILE, EnduranActivityType::RIDE],
            [SportType::CANOEING, EnduranActivityType::KAYAKING],
            [SportType::KITE_SURF, EnduranActivityType::WINDSURF],
            [SportType::SWIM, EnduranActivityType::OPEN_WATER_SWIMMING],
            [SportType::BACK_COUNTRY_SKI, EnduranActivityType::NORDIC_SKI],
            [SportType::SKATEBOARD, EnduranActivityType::INLINE_SKATING],
            [SportType::ROLLER_SKI, EnduranActivityType::NORDIC_SKI],
            [SportType::STAIR_STEPPER, EnduranActivityType::CARDIO_TRAINING],
            [SportType::VIRTUAL_ROW, EnduranActivityType::ROWING],
            [SportType::ELLIPTICAL, EnduranActivityType::CARDIO_TRAINING],
            [SportType::DANCE, EnduranActivityType::CARDIO_TRAINING],
            [SportType::PILATES, EnduranActivityType::YOGA],
            [SportType::PHYSICAL_THERAPY, EnduranActivityType::YOGA],
            [SportType::GOLF, EnduranActivityType::WORKOUT],
            [SportType::ROCK_CLIMBING, EnduranActivityType::WORKOUT],
            [SportType::BASKETBALL, EnduranActivityType::SOCCER],
            [SportType::VOLLEYBALL, EnduranActivityType::SOCCER],
            [SportType::CRICKET, EnduranActivityType::SOCCER],
            [SportType::HAND_CYCLE, EnduranActivityType::RIDE],
            [SportType::WHEELCHAIR, EnduranActivityType::WALK],
        ];
    }

    #[DataProvider('unmappedSportTypeProvider')]
    public function testDocumentedFallbacksForUnmappedSportTypes(
        SportType $sportType,
        EnduranActivityType $expectedEnduranActivityType,
    ): void {
        $this->assertSame(
            $expectedEnduranActivityType,
            EnduranActivityTypeTranslator::fromSportType($sportType),
            sprintf('Expected documented fallback for "%s"', $sportType->value)
        );
    }

    /**
     * Documented fallback behavior: Endurain activity types with no close
     * Strava equivalent resolve to a documented, sensible SportType instead
     * of throwing.
     *
     * @return array<int, array{0: EnduranActivityType, 1: SportType}>
     */
    public static function unmappedEnduranActivityTypeProvider(): array
    {
        return [
            [EnduranActivityType::LAP_SWIMMING, SportType::SWIM],
            [EnduranActivityType::TRANSITION, SportType::WORKOUT],
            [EnduranActivityType::COMMUTING_RIDE, SportType::RIDE],
            [EnduranActivityType::INDOOR_RIDE, SportType::VIRTUAL_RIDE],
            [EnduranActivityType::MIXED_SURFACE_RIDE, SportType::GRAVEL_RIDE],
            [EnduranActivityType::INDOOR_WALK, SportType::WALK],
            [EnduranActivityType::TRACK_RUN, SportType::RUN],
            [EnduranActivityType::TREADMILL_RUN, SportType::RUN],
            [EnduranActivityType::CARDIO_TRAINING, SportType::WORKOUT],
            [EnduranActivityType::JUMP_ROPE, SportType::WORKOUT],
        ];
    }

    #[DataProvider('unmappedEnduranActivityTypeProvider')]
    public function testDocumentedFallbacksForUnmappedEnduranActivityTypes(
        EnduranActivityType $enduranActivityType,
        SportType $expectedSportType,
    ): void {
        $this->assertSame(
            $expectedSportType,
            EnduranActivityTypeTranslator::toSportType($enduranActivityType),
            sprintf('Expected documented fallback for Endurain activity_type=%d', $enduranActivityType->value)
        );
    }
}
