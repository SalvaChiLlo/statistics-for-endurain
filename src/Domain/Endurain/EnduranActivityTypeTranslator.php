<?php

declare(strict_types=1);

namespace App\Domain\Endurain;

use App\Domain\Activity\SportType\SportType;

/**
 * Bidirectional, stateless lookup between Strava's string `sport_type`
 * vocabulary (@see SportType) and Endurain's integer `activity_type`
 * vocabulary (@see EnduranActivityType).
 *
 * The two vocabularies are not 1:1 in either direction:
 * - Several Strava sport types have no close Endurain equivalent (e.g.
 *   Endurain has no dedicated golf, dance, or wheelchair type). These fall
 *   back to the closest generic Endurain bucket, documented per case below.
 * - Several Endurain activity types have no close Strava equivalent (e.g.
 *   Strava's vocabulary has no triathlon "transition" or "jump rope" type).
 *   These fall back to the closest Strava SportType, documented per case
 *   below.
 *
 * No exception is ever thrown by either direction: every input resolves to
 * a documented fallback rather than failing.
 */
final class EnduranActivityTypeTranslator
{
    /**
     * Strava SportType -> Endurain EnduranActivityType.
     */
    public static function fromSportType(SportType $sportType): EnduranActivityType
    {
        return match ($sportType) {
            // Cycle.
            SportType::RIDE => EnduranActivityType::RIDE,
            SportType::MOUNTAIN_BIKE_RIDE => EnduranActivityType::MTB_RIDE,
            SportType::GRAVEL_RIDE => EnduranActivityType::GRAVEL_RIDE,
            SportType::E_BIKE_RIDE => EnduranActivityType::EBIKE_RIDE,
            SportType::E_MOUNTAIN_BIKE_RIDE => EnduranActivityType::EBIKE_MOUNTAIN_RIDE,
            SportType::VIRTUAL_RIDE => EnduranActivityType::VIRTUAL_RIDE,
            // Fallback: Endurain has no "velomobile" (enclosed, human-powered
            // vehicle) type. Closest bucket is the generic ride.
            SportType::VELO_MOBILE => EnduranActivityType::RIDE,

            // Run.
            SportType::RUN => EnduranActivityType::RUN,
            SportType::TRAIL_RUN => EnduranActivityType::TRAIL_RUN,
            SportType::VIRTUAL_RUN => EnduranActivityType::VIRTUAL_RUN,

            // Walk.
            SportType::WALK => EnduranActivityType::WALK,
            SportType::HIKE => EnduranActivityType::HIKE,

            // Water sports.
            // Fallback: Endurain has no dedicated "canoeing" type. Kayaking
            // is the closest paddle-craft equivalent.
            SportType::CANOEING => EnduranActivityType::KAYAKING,
            SportType::KAYAKING => EnduranActivityType::KAYAKING,
            // Fallback: Endurain has no "kitesurf" type. Windsurf is the
            // closest wind-powered board sport.
            SportType::KITE_SURF => EnduranActivityType::WINDSURF,
            SportType::ROWING => EnduranActivityType::ROWING,
            SportType::STAND_UP_PADDLING => EnduranActivityType::STAND_UP_PADDLING,
            SportType::SURFING => EnduranActivityType::SURF,
            // Fallback: Strava's "Swim" does not distinguish lap vs open
            // water. Endurain does; default to open water since Strava's
            // Swim is commonly used for outdoor swims in this codebase.
            SportType::SWIM => EnduranActivityType::OPEN_WATER_SWIMMING,
            SportType::WIND_SURF => EnduranActivityType::WINDSURF,

            // Winter sports.
            // Fallback: Endurain has no "backcountry ski" type. Nordic ski
            // is the closest touring/off-piste technique.
            SportType::BACK_COUNTRY_SKI => EnduranActivityType::NORDIC_SKI,
            SportType::ALPINE_SKI => EnduranActivityType::ALPINE_SKI,
            SportType::NORDIC_SKI => EnduranActivityType::NORDIC_SKI,
            SportType::ICE_SKATE => EnduranActivityType::ICE_SKATE,
            SportType::SNOWBOARD => EnduranActivityType::SNOWBOARD,
            SportType::SNOWSHOE => EnduranActivityType::SNOW_SHOEING,

            // Skating.
            // Fallback: Endurain has no dedicated "skateboard" type. Inline
            // skating is the closest wheeled-board/skate discipline.
            SportType::SKATEBOARD => EnduranActivityType::INLINE_SKATING,
            SportType::INLINE_SKATE => EnduranActivityType::INLINE_SKATING,
            // Fallback: Endurain has no "roller ski" type. Nordic ski is the
            // closest technique (roller skiing is off-snow nordic training).
            SportType::ROLLER_SKI => EnduranActivityType::NORDIC_SKI,

            // Racquet & Paddle Sports.
            SportType::BADMINTON => EnduranActivityType::BADMINTON,
            SportType::PICKLE_BALL => EnduranActivityType::PICKLEBALL,
            SportType::RACQUET_BALL => EnduranActivityType::RACQUETBALL,
            SportType::SQUASH => EnduranActivityType::SQUASH,
            SportType::TABLE_TENNIS => EnduranActivityType::TABLE_TENNIS,
            SportType::TENNIS => EnduranActivityType::TENNIS,
            SportType::PADEL => EnduranActivityType::PADEL,

            // Fitness.
            SportType::CROSSFIT => EnduranActivityType::CROSSFIT,
            SportType::WEIGHT_TRAINING => EnduranActivityType::STRENGTH_TRAINING,
            SportType::WORKOUT => EnduranActivityType::WORKOUT,
            // Fallback: Endurain has no "stair stepper" type. Cardio
            // training is the closest generic cardio-machine bucket.
            SportType::STAIR_STEPPER => EnduranActivityType::CARDIO_TRAINING,
            // Fallback: Endurain has no separate "virtual row" type. Rowing
            // is the closest equivalent.
            SportType::VIRTUAL_ROW => EnduranActivityType::ROWING,
            SportType::HIIT => EnduranActivityType::HIIT,
            // Fallback: Endurain has no "elliptical" type. Cardio training
            // is the closest generic cardio-machine bucket.
            SportType::ELLIPTICAL => EnduranActivityType::CARDIO_TRAINING,
            // Fallback: Endurain has no "dance" type. Cardio training is the
            // closest generic fitness bucket.
            SportType::DANCE => EnduranActivityType::CARDIO_TRAINING,

            // Mind & Body Sports.
            // Fallback: Endurain has no "pilates" type. Yoga is Endurain's
            // only mind-body bucket and the closest equivalent.
            SportType::PILATES => EnduranActivityType::YOGA,
            SportType::YOGA => EnduranActivityType::YOGA,
            // Fallback: Endurain has no "physical therapy" type. Yoga is the
            // closest low-intensity, mind-body/recovery bucket available.
            SportType::PHYSICAL_THERAPY => EnduranActivityType::YOGA,

            // Outdoor Sports.
            // Fallback: Endurain has no "golf" type. Generic workout is the
            // closest catch-all bucket.
            SportType::GOLF => EnduranActivityType::WORKOUT,
            // Fallback: Endurain has no "rock climbing" type. Generic
            // workout is the closest catch-all bucket.
            SportType::ROCK_CLIMBING => EnduranActivityType::WORKOUT,
            SportType::SAIL => EnduranActivityType::SAILING,

            // Team Sports.
            // Fallback: Endurain's only team-sport type is soccer. Basketball
            // has no dedicated equivalent.
            SportType::BASKETBALL => EnduranActivityType::SOCCER,
            SportType::SOCCER => EnduranActivityType::SOCCER,
            // Fallback: Endurain's only team-sport type is soccer. Volleyball
            // has no dedicated equivalent.
            SportType::VOLLEYBALL => EnduranActivityType::SOCCER,
            // Fallback: Endurain's only team-sport type is soccer. Cricket
            // has no dedicated equivalent.
            SportType::CRICKET => EnduranActivityType::SOCCER,

            // Adaptive & Inclusive Sports.
            // Fallback: Endurain has no "handcycle" type. Generic ride is
            // the closest human-powered-cycling equivalent.
            SportType::HAND_CYCLE => EnduranActivityType::RIDE,
            // Fallback: Endurain has no "wheelchair" type. Walk is the
            // closest ambulatory-equivalent bucket.
            SportType::WHEELCHAIR => EnduranActivityType::WALK,
        };
    }

    /**
     * Endurain EnduranActivityType -> Strava SportType.
     */
    public static function toSportType(EnduranActivityType $enduranActivityType): SportType
    {
        return match ($enduranActivityType) {
            EnduranActivityType::RUN => SportType::RUN,
            EnduranActivityType::TRAIL_RUN => SportType::TRAIL_RUN,
            EnduranActivityType::VIRTUAL_RUN => SportType::VIRTUAL_RUN,
            EnduranActivityType::RIDE => SportType::RIDE,
            EnduranActivityType::GRAVEL_RIDE => SportType::GRAVEL_RIDE,
            EnduranActivityType::MTB_RIDE => SportType::MOUNTAIN_BIKE_RIDE,
            EnduranActivityType::VIRTUAL_RIDE => SportType::VIRTUAL_RIDE,
            // Fallback: Strava's "Swim" does not distinguish lap vs open
            // water; both Endurain swim types map back to Swim.
            EnduranActivityType::LAP_SWIMMING => SportType::SWIM,
            EnduranActivityType::OPEN_WATER_SWIMMING => SportType::SWIM,
            EnduranActivityType::WORKOUT => SportType::WORKOUT,
            EnduranActivityType::WALK => SportType::WALK,
            EnduranActivityType::HIKE => SportType::HIKE,
            EnduranActivityType::ROWING => SportType::ROWING,
            EnduranActivityType::YOGA => SportType::YOGA,
            EnduranActivityType::ALPINE_SKI => SportType::ALPINE_SKI,
            EnduranActivityType::NORDIC_SKI => SportType::NORDIC_SKI,
            EnduranActivityType::SNOWBOARD => SportType::SNOWBOARD,
            // Fallback: Strava has no triathlon "transition" sport type.
            // Generic workout is the closest catch-all.
            EnduranActivityType::TRANSITION => SportType::WORKOUT,
            EnduranActivityType::STRENGTH_TRAINING => SportType::WEIGHT_TRAINING,
            EnduranActivityType::CROSSFIT => SportType::CROSSFIT,
            EnduranActivityType::TENNIS => SportType::TENNIS,
            EnduranActivityType::TABLE_TENNIS => SportType::TABLE_TENNIS,
            EnduranActivityType::BADMINTON => SportType::BADMINTON,
            EnduranActivityType::SQUASH => SportType::SQUASH,
            EnduranActivityType::RACQUETBALL => SportType::RACQUET_BALL,
            EnduranActivityType::PICKLEBALL => SportType::PICKLE_BALL,
            // Fallback: Strava has no "commuting ride" sport type. Generic
            // ride is the closest equivalent.
            EnduranActivityType::COMMUTING_RIDE => SportType::RIDE,
            // Fallback: Strava has no "indoor ride" sport type. Virtual ride
            // is the closest indoor/trainer equivalent.
            EnduranActivityType::INDOOR_RIDE => SportType::VIRTUAL_RIDE,
            // Fallback: Strava has no "mixed surface ride" sport type.
            // Gravel ride is the closest surface-mixed equivalent.
            EnduranActivityType::MIXED_SURFACE_RIDE => SportType::GRAVEL_RIDE,
            EnduranActivityType::WINDSURF => SportType::WIND_SURF,
            // Fallback: Strava has no "indoor walk" sport type. Walk is the
            // closest equivalent.
            EnduranActivityType::INDOOR_WALK => SportType::WALK,
            EnduranActivityType::STAND_UP_PADDLING => SportType::STAND_UP_PADDLING,
            EnduranActivityType::SURF => SportType::SURFING,
            // Fallback: Strava has no "track run" sport type. Generic run
            // is the closest equivalent.
            EnduranActivityType::TRACK_RUN => SportType::RUN,
            EnduranActivityType::EBIKE_RIDE => SportType::E_BIKE_RIDE,
            EnduranActivityType::EBIKE_MOUNTAIN_RIDE => SportType::E_MOUNTAIN_BIKE_RIDE,
            EnduranActivityType::ICE_SKATE => SportType::ICE_SKATE,
            EnduranActivityType::SOCCER => SportType::SOCCER,
            EnduranActivityType::PADEL => SportType::PADEL,
            // Fallback: Strava has no "treadmill run" sport type. Generic
            // run is the closest equivalent.
            EnduranActivityType::TREADMILL_RUN => SportType::RUN,
            // Fallback: Strava has no generic "cardio training" sport type.
            // Workout is the closest catch-all.
            EnduranActivityType::CARDIO_TRAINING => SportType::WORKOUT,
            EnduranActivityType::KAYAKING => SportType::KAYAKING,
            EnduranActivityType::SAILING => SportType::SAIL,
            EnduranActivityType::SNOW_SHOEING => SportType::SNOWSHOE,
            EnduranActivityType::INLINE_SKATING => SportType::INLINE_SKATE,
            EnduranActivityType::HIIT => SportType::HIIT,
            // Fallback: Strava has no "jump rope" sport type. Workout is the
            // closest catch-all.
            EnduranActivityType::JUMP_ROPE => SportType::WORKOUT,
        };
    }
}
