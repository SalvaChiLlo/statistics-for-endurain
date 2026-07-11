<?php

declare(strict_types=1);

namespace App\Tests\Domain\Endurain;

use App\Domain\Endurain\EndurainGearTranslator;
use App\Domain\Gear\GearId;
use App\Domain\Gear\GearType;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

class EndurainGearTranslatorTest extends TestCase
{
    public function testToGearIdPrefixesTheRawIdToAvoidCollisionWithStravaGearIds(): void
    {
        $this->assertEquals(
            GearId::fromUnprefixed('endurain-42'),
            EndurainGearTranslator::toGearId($this->buildRawEndurainGear())
        );
    }

    public function testToNameUsesNickname(): void
    {
        $this->assertEquals('My Commuter Bike', EndurainGearTranslator::toName($this->buildRawEndurainGear()));
    }

    public function testToIsRetiredIsInverseOfActive(): void
    {
        $this->assertFalse(EndurainGearTranslator::toIsRetired([
            ...$this->buildRawEndurainGear(),
            'active' => true,
        ]));

        $this->assertTrue(EndurainGearTranslator::toIsRetired([
            ...$this->buildRawEndurainGear(),
            'active' => false,
        ]));
    }

    public function testToIsRetiredDefaultsToNotRetiredWhenActiveIsAbsent(): void
    {
        $rawGear = $this->buildRawEndurainGear();
        unset($rawGear['active']);

        $this->assertFalse(EndurainGearTranslator::toIsRetired($rawGear));
    }

    public function testToGearBuildsAFullGearEntity(): void
    {
        $gear = EndurainGearTranslator::toGear(
            $this->buildRawEndurainGear(),
            SerializableDateTime::fromString('2026-07-11 12:00:00'),
        );

        $this->assertEquals(GearId::fromUnprefixed('endurain-42'), $gear->getId());
        $this->assertEquals('My Commuter Bike', $gear->getOriginalName());
        $this->assertFalse($gear->isRetired());
        $this->assertEquals(GearType::IMPORTED, $gear->getType());
        $this->assertEquals('2026-07-11 12:00:00', (string) $gear->getCreatedOn());
    }

    public function testToGearForRetiredGear(): void
    {
        $gear = EndurainGearTranslator::toGear(
            [
                ...$this->buildRawEndurainGear(),
                'active' => false,
            ],
            SerializableDateTime::fromString('2026-07-11 12:00:00'),
        );

        $this->assertTrue($gear->isRetired());
    }

    /**
     * @return array<mixed>
     */
    private function buildRawEndurainGear(): array
    {
        // Confirmed shape from Endurain's backend/app/gears/gear/schema.py and models.py.
        return [
            'id' => 42,
            'user_id' => 1,
            'brand' => 'Canyon',
            'model' => 'Grail',
            'nickname' => 'My Commuter Bike',
            'gear_type' => 1,
            'created_at' => '2026-01-01T12:00:00',
            'active' => true,
            'initial_kms' => 0.0,
            'purchase_value' => null,
            'strava_gear_id' => null,
            'garminconnect_gear_id' => null,
        ];
    }
}
