<?php

declare(strict_types=1);

namespace App\Domain\Endurain;

/**
 * Endurain's `activity_type` integer vocabulary (codes 1-47).
 *
 * Source of truth: ACTIVITY_TYPE_LABEL_KEYS in
 * https://raw.githubusercontent.com/endurain-project/endurain/master/frontend/src/features/activities/utils/activityType.ts
 * (fetched 2026-07-11, `master` branch).
 */
enum EnduranActivityType: int
{
    case RUN = 1;
    case TRAIL_RUN = 2;
    case VIRTUAL_RUN = 3;
    case RIDE = 4;
    case GRAVEL_RIDE = 5;
    case MTB_RIDE = 6;
    case VIRTUAL_RIDE = 7;
    case LAP_SWIMMING = 8;
    case OPEN_WATER_SWIMMING = 9;
    case WORKOUT = 10;
    case WALK = 11;
    case HIKE = 12;
    case ROWING = 13;
    case YOGA = 14;
    case ALPINE_SKI = 15;
    case NORDIC_SKI = 16;
    case SNOWBOARD = 17;
    case TRANSITION = 18;
    case STRENGTH_TRAINING = 19;
    case CROSSFIT = 20;
    case TENNIS = 21;
    case TABLE_TENNIS = 22;
    case BADMINTON = 23;
    case SQUASH = 24;
    case RACQUETBALL = 25;
    case PICKLEBALL = 26;
    case COMMUTING_RIDE = 27;
    case INDOOR_RIDE = 28;
    case MIXED_SURFACE_RIDE = 29;
    case WINDSURF = 30;
    case INDOOR_WALK = 31;
    case STAND_UP_PADDLING = 32;
    case SURF = 33;
    case TRACK_RUN = 34;
    case EBIKE_RIDE = 35;
    case EBIKE_MOUNTAIN_RIDE = 36;
    case ICE_SKATE = 37;
    case SOCCER = 38;
    case PADEL = 39;
    case TREADMILL_RUN = 40;
    case CARDIO_TRAINING = 41;
    case KAYAKING = 42;
    case SAILING = 43;
    case SNOW_SHOEING = 44;
    case INLINE_SKATING = 45;
    case HIIT = 46;
    case JUMP_ROPE = 47;
}
