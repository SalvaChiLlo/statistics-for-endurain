<?php

declare(strict_types=1);

namespace App\Tests\Domain\Endurain;

use App\Domain\Activity\SportType\SportType;
use App\Domain\Endurain\EndurainActivityType;
use App\Domain\Endurain\EndurainActivityTypeTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EndurainActivityTypeTranslatorTest extends TestCase
{
    /**
     * Every Strava SportType must map to some EndurainActivityType without
     * throwing.
     */
    public function testEverySportTypeMapsToAnEndurainActivityType(): void
    {
        foreach (SportType::cases() as $sportType) {
            $endurainActivityType = EndurainActivityTypeTranslator::fromSportType($sportType);
            $this->assertInstanceOf(EndurainActivityType::class, $endurainActivityType);
        }
    }

    /**
     * Every Endurain activity_type code (1-47) must map to some SportType
     * without throwing.
     */
    public function testEveryEndurainActivityTypeMapsToASportType(): void
    {
        foreach (EndurainActivityType::cases() as $endurainActivityType) {
            $sportType = EndurainActivityTypeTranslator::toSportType($endurainActivityType);
            $this->assertInstanceOf(SportType::class, $sportType);
        }
    }

    public function testEndurainActivityTypeVocabularyCoversCodesOneTo47(): void
    {
        $values = array_map(fn (EndurainActivityType $type) => $type->value, EndurainActivityType::cases());
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
            $endurainActivityType = EndurainActivityTypeTranslator::fromSportType($sportType);
            $this->assertSame(
                $sportType,
                EndurainActivityTypeTranslator::toSportType($endurainActivityType),
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
     * @return array<int, array{0: SportType, 1: EndurainActivityType}>
     */
    public static function unmappedSportTypeProvider(): array
    {
        return [
            [SportType::VELO_MOBILE, EndurainActivityType::RIDE],
            [SportType::CANOEING, EndurainActivityType::KAYAKING],
            [SportType::KITE_SURF, EndurainActivityType::WINDSURF],
            [SportType::SWIM, EndurainActivityType::OPEN_WATER_SWIMMING],
            [SportType::BACK_COUNTRY_SKI, EndurainActivityType::NORDIC_SKI],
            [SportType::SKATEBOARD, EndurainActivityType::INLINE_SKATING],
            [SportType::ROLLER_SKI, EndurainActivityType::NORDIC_SKI],
            [SportType::STAIR_STEPPER, EndurainActivityType::CARDIO_TRAINING],
            [SportType::VIRTUAL_ROW, EndurainActivityType::ROWING],
            [SportType::ELLIPTICAL, EndurainActivityType::CARDIO_TRAINING],
            [SportType::DANCE, EndurainActivityType::CARDIO_TRAINING],
            [SportType::PILATES, EndurainActivityType::YOGA],
            [SportType::PHYSICAL_THERAPY, EndurainActivityType::YOGA],
            [SportType::GOLF, EndurainActivityType::WORKOUT],
            [SportType::ROCK_CLIMBING, EndurainActivityType::WORKOUT],
            [SportType::BASKETBALL, EndurainActivityType::SOCCER],
            [SportType::VOLLEYBALL, EndurainActivityType::SOCCER],
            [SportType::CRICKET, EndurainActivityType::SOCCER],
            [SportType::HAND_CYCLE, EndurainActivityType::RIDE],
            [SportType::WHEELCHAIR, EndurainActivityType::WALK],
        ];
    }

    #[DataProvider('unmappedSportTypeProvider')]
    public function testDocumentedFallbacksForUnmappedSportTypes(
        SportType $sportType,
        EndurainActivityType $expectedEndurainActivityType,
    ): void {
        $this->assertSame(
            $expectedEndurainActivityType,
            EndurainActivityTypeTranslator::fromSportType($sportType),
            sprintf('Expected documented fallback for "%s"', $sportType->value)
        );
    }

    /**
     * Documented fallback behavior: Endurain activity types with no close
     * Strava equivalent resolve to a documented, sensible SportType instead
     * of throwing.
     *
     * @return array<int, array{0: EndurainActivityType, 1: SportType}>
     */
    public static function unmappedEndurainActivityTypeProvider(): array
    {
        return [
            [EndurainActivityType::LAP_SWIMMING, SportType::SWIM],
            [EndurainActivityType::TRANSITION, SportType::WORKOUT],
            [EndurainActivityType::COMMUTING_RIDE, SportType::RIDE],
            [EndurainActivityType::INDOOR_RIDE, SportType::VIRTUAL_RIDE],
            [EndurainActivityType::MIXED_SURFACE_RIDE, SportType::GRAVEL_RIDE],
            [EndurainActivityType::INDOOR_WALK, SportType::WALK],
            [EndurainActivityType::TRACK_RUN, SportType::RUN],
            [EndurainActivityType::TREADMILL_RUN, SportType::RUN],
            [EndurainActivityType::CARDIO_TRAINING, SportType::WORKOUT],
            [EndurainActivityType::JUMP_ROPE, SportType::WORKOUT],
        ];
    }

    #[DataProvider('unmappedEndurainActivityTypeProvider')]
    public function testDocumentedFallbacksForUnmappedEndurainActivityTypes(
        EndurainActivityType $endurainActivityType,
        SportType $expectedSportType,
    ): void {
        $this->assertSame(
            $expectedSportType,
            EndurainActivityTypeTranslator::toSportType($endurainActivityType),
            sprintf('Expected documented fallback for Endurain activity_type=%d', $endurainActivityType->value)
        );
    }
}
