<?php

declare(strict_types=1);

namespace App\Domain\Endurain;

use App\Domain\Activity\SportType\SportType;

/**
 * Bidirectional, stateless lookup between Strava's string `sport_type`
 * vocabulary (@see SportType) and Endurain's integer `activity_type`
 * vocabulary (@see EndurainActivityType).
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
final class EndurainActivityTypeTranslator
{
    /**
     * Strava SportType -> Endurain EndurainActivityType.
     */
    public static function fromSportType(SportType $sportType): EndurainActivityType
    {
        return match ($sportType) {
            // Cycle.
            SportType::RIDE => EndurainActivityType::RIDE,
            SportType::MOUNTAIN_BIKE_RIDE => EndurainActivityType::MTB_RIDE,
            SportType::GRAVEL_RIDE => EndurainActivityType::GRAVEL_RIDE,
            SportType::E_BIKE_RIDE => EndurainActivityType::EBIKE_RIDE,
            SportType::E_MOUNTAIN_BIKE_RIDE => EndurainActivityType::EBIKE_MOUNTAIN_RIDE,
            SportType::VIRTUAL_RIDE => EndurainActivityType::VIRTUAL_RIDE,
            // Fallback: Endurain has no "velomobile" (enclosed, human-powered
            // vehicle) type. Closest bucket is the generic ride.
            SportType::VELO_MOBILE => EndurainActivityType::RIDE,

            // Run.
            SportType::RUN => EndurainActivityType::RUN,
            SportType::TRAIL_RUN => EndurainActivityType::TRAIL_RUN,
            SportType::VIRTUAL_RUN => EndurainActivityType::VIRTUAL_RUN,

            // Walk.
            SportType::WALK => EndurainActivityType::WALK,
            SportType::HIKE => EndurainActivityType::HIKE,

            // Water sports.
            // Fallback: Endurain has no dedicated "canoeing" type. Kayaking
            // is the closest paddle-craft equivalent.
            SportType::CANOEING => EndurainActivityType::KAYAKING,
            SportType::KAYAKING => EndurainActivityType::KAYAKING,
            // Fallback: Endurain has no "kitesurf" type. Windsurf is the
            // closest wind-powered board sport.
            SportType::KITE_SURF => EndurainActivityType::WINDSURF,
            SportType::ROWING => EndurainActivityType::ROWING,
            SportType::STAND_UP_PADDLING => EndurainActivityType::STAND_UP_PADDLING,
            SportType::SURFING => EndurainActivityType::SURF,
            // Fallback: Strava's "Swim" does not distinguish lap vs open
            // water. Endurain does; default to open water since Strava's
            // Swim is commonly used for outdoor swims in this codebase.
            SportType::SWIM => EndurainActivityType::OPEN_WATER_SWIMMING,
            SportType::WIND_SURF => EndurainActivityType::WINDSURF,

            // Winter sports.
            // Fallback: Endurain has no "backcountry ski" type. Nordic ski
            // is the closest touring/off-piste technique.
            SportType::BACK_COUNTRY_SKI => EndurainActivityType::NORDIC_SKI,
            SportType::ALPINE_SKI => EndurainActivityType::ALPINE_SKI,
            SportType::NORDIC_SKI => EndurainActivityType::NORDIC_SKI,
            SportType::ICE_SKATE => EndurainActivityType::ICE_SKATE,
            SportType::SNOWBOARD => EndurainActivityType::SNOWBOARD,
            SportType::SNOWSHOE => EndurainActivityType::SNOW_SHOEING,

            // Skating.
            // Fallback: Endurain has no dedicated "skateboard" type. Inline
            // skating is the closest wheeled-board/skate discipline.
            SportType::SKATEBOARD => EndurainActivityType::INLINE_SKATING,
            SportType::INLINE_SKATE => EndurainActivityType::INLINE_SKATING,
            // Fallback: Endurain has no "roller ski" type. Nordic ski is the
            // closest technique (roller skiing is off-snow nordic training).
            SportType::ROLLER_SKI => EndurainActivityType::NORDIC_SKI,

            // Racquet & Paddle Sports.
            SportType::BADMINTON => EndurainActivityType::BADMINTON,
            SportType::PICKLE_BALL => EndurainActivityType::PICKLEBALL,
            SportType::RACQUET_BALL => EndurainActivityType::RACQUETBALL,
            SportType::SQUASH => EndurainActivityType::SQUASH,
            SportType::TABLE_TENNIS => EndurainActivityType::TABLE_TENNIS,
            SportType::TENNIS => EndurainActivityType::TENNIS,
            SportType::PADEL => EndurainActivityType::PADEL,

            // Fitness.
            SportType::CROSSFIT => EndurainActivityType::CROSSFIT,
            SportType::WEIGHT_TRAINING => EndurainActivityType::STRENGTH_TRAINING,
            SportType::WORKOUT => EndurainActivityType::WORKOUT,
            // Fallback: Endurain has no "stair stepper" type. Cardio
            // training is the closest generic cardio-machine bucket.
            SportType::STAIR_STEPPER => EndurainActivityType::CARDIO_TRAINING,
            // Fallback: Endurain has no separate "virtual row" type. Rowing
            // is the closest equivalent.
            SportType::VIRTUAL_ROW => EndurainActivityType::ROWING,
            SportType::HIIT => EndurainActivityType::HIIT,
            // Fallback: Endurain has no "elliptical" type. Cardio training
            // is the closest generic cardio-machine bucket.
            SportType::ELLIPTICAL => EndurainActivityType::CARDIO_TRAINING,
            // Fallback: Endurain has no "dance" type. Cardio training is the
            // closest generic fitness bucket.
            SportType::DANCE => EndurainActivityType::CARDIO_TRAINING,

            // Mind & Body Sports.
            // Fallback: Endurain has no "pilates" type. Yoga is Endurain's
            // only mind-body bucket and the closest equivalent.
            SportType::PILATES => EndurainActivityType::YOGA,
            SportType::YOGA => EndurainActivityType::YOGA,
            // Fallback: Endurain has no "physical therapy" type. Yoga is the
            // closest low-intensity, mind-body/recovery bucket available.
            SportType::PHYSICAL_THERAPY => EndurainActivityType::YOGA,

            // Outdoor Sports.
            // Fallback: Endurain has no "golf" type. Generic workout is the
            // closest catch-all bucket.
            SportType::GOLF => EndurainActivityType::WORKOUT,
            // Fallback: Endurain has no "rock climbing" type. Generic
            // workout is the closest catch-all bucket.
            SportType::ROCK_CLIMBING => EndurainActivityType::WORKOUT,
            SportType::SAIL => EndurainActivityType::SAILING,

            // Team Sports.
            // Fallback: Endurain's only team-sport type is soccer. Basketball
            // has no dedicated equivalent.
            SportType::BASKETBALL => EndurainActivityType::SOCCER,
            SportType::SOCCER => EndurainActivityType::SOCCER,
            // Fallback: Endurain's only team-sport type is soccer. Volleyball
            // has no dedicated equivalent.
            SportType::VOLLEYBALL => EndurainActivityType::SOCCER,
            // Fallback: Endurain's only team-sport type is soccer. Cricket
            // has no dedicated equivalent.
            SportType::CRICKET => EndurainActivityType::SOCCER,

            // Adaptive & Inclusive Sports.
            // Fallback: Endurain has no "handcycle" type. Generic ride is
            // the closest human-powered-cycling equivalent.
            SportType::HAND_CYCLE => EndurainActivityType::RIDE,
            // Fallback: Endurain has no "wheelchair" type. Walk is the
            // closest ambulatory-equivalent bucket.
            SportType::WHEELCHAIR => EndurainActivityType::WALK,
        };
    }

    /**
     * Endurain EndurainActivityType -> Strava SportType.
     */
    public static function toSportType(EndurainActivityType $endurainActivityType): SportType
    {
        return match ($endurainActivityType) {
            EndurainActivityType::RUN => SportType::RUN,
            EndurainActivityType::TRAIL_RUN => SportType::TRAIL_RUN,
            EndurainActivityType::VIRTUAL_RUN => SportType::VIRTUAL_RUN,
            EndurainActivityType::RIDE => SportType::RIDE,
            EndurainActivityType::GRAVEL_RIDE => SportType::GRAVEL_RIDE,
            EndurainActivityType::MTB_RIDE => SportType::MOUNTAIN_BIKE_RIDE,
            EndurainActivityType::VIRTUAL_RIDE => SportType::VIRTUAL_RIDE,
            // Fallback: Strava's "Swim" does not distinguish lap vs open
            // water; both Endurain swim types map back to Swim.
            EndurainActivityType::LAP_SWIMMING => SportType::SWIM,
            EndurainActivityType::OPEN_WATER_SWIMMING => SportType::SWIM,
            EndurainActivityType::WORKOUT => SportType::WORKOUT,
            EndurainActivityType::WALK => SportType::WALK,
            EndurainActivityType::HIKE => SportType::HIKE,
            EndurainActivityType::ROWING => SportType::ROWING,
            EndurainActivityType::YOGA => SportType::YOGA,
            EndurainActivityType::ALPINE_SKI => SportType::ALPINE_SKI,
            EndurainActivityType::NORDIC_SKI => SportType::NORDIC_SKI,
            EndurainActivityType::SNOWBOARD => SportType::SNOWBOARD,
            // Fallback: Strava has no triathlon "transition" sport type.
            // Generic workout is the closest catch-all.
            EndurainActivityType::TRANSITION => SportType::WORKOUT,
            EndurainActivityType::STRENGTH_TRAINING => SportType::WEIGHT_TRAINING,
            EndurainActivityType::CROSSFIT => SportType::CROSSFIT,
            EndurainActivityType::TENNIS => SportType::TENNIS,
            EndurainActivityType::TABLE_TENNIS => SportType::TABLE_TENNIS,
            EndurainActivityType::BADMINTON => SportType::BADMINTON,
            EndurainActivityType::SQUASH => SportType::SQUASH,
            EndurainActivityType::RACQUETBALL => SportType::RACQUET_BALL,
            EndurainActivityType::PICKLEBALL => SportType::PICKLE_BALL,
            // Fallback: Strava has no "commuting ride" sport type. Generic
            // ride is the closest equivalent.
            EndurainActivityType::COMMUTING_RIDE => SportType::RIDE,
            // Fallback: Strava has no "indoor ride" sport type. Virtual ride
            // is the closest indoor/trainer equivalent.
            EndurainActivityType::INDOOR_RIDE => SportType::VIRTUAL_RIDE,
            // Fallback: Strava has no "mixed surface ride" sport type.
            // Gravel ride is the closest surface-mixed equivalent.
            EndurainActivityType::MIXED_SURFACE_RIDE => SportType::GRAVEL_RIDE,
            EndurainActivityType::WINDSURF => SportType::WIND_SURF,
            // Fallback: Strava has no "indoor walk" sport type. Walk is the
            // closest equivalent.
            EndurainActivityType::INDOOR_WALK => SportType::WALK,
            EndurainActivityType::STAND_UP_PADDLING => SportType::STAND_UP_PADDLING,
            EndurainActivityType::SURF => SportType::SURFING,
            // Fallback: Strava has no "track run" sport type. Generic run
            // is the closest equivalent.
            EndurainActivityType::TRACK_RUN => SportType::RUN,
            EndurainActivityType::EBIKE_RIDE => SportType::E_BIKE_RIDE,
            EndurainActivityType::EBIKE_MOUNTAIN_RIDE => SportType::E_MOUNTAIN_BIKE_RIDE,
            EndurainActivityType::ICE_SKATE => SportType::ICE_SKATE,
            EndurainActivityType::SOCCER => SportType::SOCCER,
            EndurainActivityType::PADEL => SportType::PADEL,
            // Fallback: Strava has no "treadmill run" sport type. Generic
            // run is the closest equivalent.
            EndurainActivityType::TREADMILL_RUN => SportType::RUN,
            // Fallback: Strava has no generic "cardio training" sport type.
            // Workout is the closest catch-all.
            EndurainActivityType::CARDIO_TRAINING => SportType::WORKOUT,
            EndurainActivityType::KAYAKING => SportType::KAYAKING,
            EndurainActivityType::SAILING => SportType::SAIL,
            EndurainActivityType::SNOW_SHOEING => SportType::SNOWSHOE,
            EndurainActivityType::INLINE_SKATING => SportType::INLINE_SKATE,
            EndurainActivityType::HIIT => SportType::HIIT,
            // Fallback: Strava has no "jump rope" sport type. Workout is the
            // closest catch-all.
            EndurainActivityType::JUMP_ROPE => SportType::WORKOUT,
        };
    }
}
