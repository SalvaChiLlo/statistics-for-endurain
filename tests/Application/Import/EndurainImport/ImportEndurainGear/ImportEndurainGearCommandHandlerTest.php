<?php

declare(strict_types=1);

namespace App\Tests\Application\Import\EndurainImport\ImportEndurainGear;

use App\Application\Import\EndurainImport\ImportEndurainGear\ImportEndurainGear;
use App\Application\Import\EndurainImport\ImportEndurainGear\ImportEndurainGearCommandHandler;
use App\Domain\Endurain\Endurain;
use App\Domain\Gear\Gear;
use App\Domain\Gear\GearId;
use App\Domain\Gear\GearRepository;
use App\Domain\Gear\GearType;
use App\Infrastructure\Exception\EntityNotFound;
use App\Tests\Infrastructure\Time\Clock\PausedClock;
use App\Tests\SpyOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ImportEndurainGearCommandHandlerTest extends TestCase
{
    private MockObject $endurain;
    private MockObject $gearRepository;
    private ImportEndurainGearCommandHandler $handler;

    public function testHandleAddsNewGearWhenItDoesNotExistYet(): void
    {
        $this->endurain
            ->expects($this->once())
            ->method('getGear')
            ->with(42)
            ->willReturn($this->buildRawEndurainGear());

        $this->gearRepository
            ->expects($this->once())
            ->method('find')
            ->with(GearId::fromUnprefixed('endurain-42'))
            ->willThrowException(new EntityNotFound());

        $this->gearRepository
            ->expects($this->once())
            ->method('add')
            ->with($this->callback(function (Gear $gear): bool {
                $this->assertEquals(GearId::fromUnprefixed('endurain-42'), $gear->getId());
                $this->assertEquals('My Commuter Bike', $gear->getOriginalName());
                $this->assertFalse($gear->isRetired());
                $this->assertEquals(GearType::IMPORTED, $gear->getType());

                return true;
            }));

        $this->gearRepository
            ->expects($this->never())
            ->method('update');

        $output = new SpyOutput();
        $this->handler->handle(new ImportEndurainGear(
            output: $output,
            endurainGearId: 42,
        ));

        $this->assertStringContainsString('Imported gear "My Commuter Bike"', (string) $output);
    }

    public function testHandleUpdatesNameAndIsRetiredWhenGearAlreadyExists(): void
    {
        $this->endurain
            ->expects($this->once())
            ->method('getGear')
            ->with(42)
            ->willReturn([
                ...$this->buildRawEndurainGear(),
                'nickname' => 'Renamed Bike',
                'active' => false,
            ]);

        $existingGear = Gear::create(
            gearId: GearId::fromUnprefixed('endurain-42'),
            createdOn: PausedClock::fromString('2026-01-01 00:00:00')->getCurrentDateTimeImmutable(),
            name: 'My Commuter Bike',
            isRetired: false,
            type: GearType::IMPORTED,
        );

        $this->gearRepository
            ->expects($this->once())
            ->method('find')
            ->with(GearId::fromUnprefixed('endurain-42'))
            ->willReturn($existingGear);

        $this->gearRepository
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function (Gear $gear): bool {
                $this->assertEquals('Renamed Bike', $gear->getOriginalName());
                $this->assertTrue($gear->isRetired());

                return true;
            }));

        $this->gearRepository
            ->expects($this->never())
            ->method('add');

        $output = new SpyOutput();
        $this->handler->handle(new ImportEndurainGear(
            output: $output,
            endurainGearId: 42,
        ));

        $this->assertStringContainsString('Imported gear "Renamed Bike ☠️"', (string) $output);
    }

    /**
     * @return array<mixed>
     */
    private function buildRawEndurainGear(): array
    {
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

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->endurain = $this->createMock(Endurain::class);
        $this->gearRepository = $this->createMock(GearRepository::class);

        $this->handler = new ImportEndurainGearCommandHandler(
            endurain: $this->endurain,
            gearRepository: $this->gearRepository,
            clock: PausedClock::fromString('2026-07-11 12:00:00'),
        );
    }
}
