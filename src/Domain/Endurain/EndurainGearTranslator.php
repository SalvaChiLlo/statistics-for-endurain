<?php

declare(strict_types=1);

namespace App\Domain\Endurain;

use App\Domain\Gear\Gear;
use App\Domain\Gear\GearId;
use App\Domain\Gear\GearType;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

/**
 * Translates a raw Endurain gear payload (`GET /api/v1/gears/id/{id}`, or a
 * `records` entry from `GET /api/v1/gears`) into this codebase's `Gear`
 * domain entity.
 *
 * Endurain's `gear_type` (1=bike, 2=shoes, 3=wetsuit, 4=racquet, 5=skis,
 * 6=snowboard, 7=windsurf, 8=water sports board) is intentionally NOT
 * translated: `App\Domain\Gear\GearType` only distinguishes import
 * provenance (`IMPORTED` vs `CUSTOM`), not equipment category, and nothing
 * in this codebase consumes an equipment-category concept on `Gear`.
 * Modelling it here would be speculative.
 */
final class EndurainGearTranslator
{
    /**
     * Endurain's gear ids are a separate integer sequence from Strava's.
     * Prefix the raw id so gear imported from either source can never
     * collide on the same internal GearId, mirroring ActivityId's
     * "endurain-" convention.
     *
     * @param array<mixed> $rawGearData
     */
    public static function toGearId(array $rawGearData): GearId
    {
        return GearId::fromUnprefixed('endurain-'.$rawGearData['id']);
    }

    /**
     * Endurain's `nickname` is the required, user-facing display name for a
     * piece of gear (unlike `brand`/`model`, which are optional), matching
     * how Strava's "name" is used for this entity's `name`.
     *
     * @param array<mixed> $rawGearData
     */
    public static function toName(array $rawGearData): string
    {
        return (string) $rawGearData['nickname'];
    }

    /**
     * Endurain models retirement inversely as "active" (default true), while
     * this codebase models it directly as "isRetired".
     *
     * @param array<mixed> $rawGearData
     */
    public static function toIsRetired(array $rawGearData): bool
    {
        return !($rawGearData['active'] ?? true);
    }

    /**
     * @param array<mixed> $rawGearData
     */
    public static function toGear(array $rawGearData, SerializableDateTime $createdOn): Gear
    {
        return Gear::create(
            gearId: self::toGearId($rawGearData),
            createdOn: $createdOn,
            name: self::toName($rawGearData),
            isRetired: self::toIsRetired($rawGearData),
            type: GearType::IMPORTED,
        );
    }
}
