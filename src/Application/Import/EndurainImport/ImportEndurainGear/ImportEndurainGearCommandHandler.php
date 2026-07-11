<?php

declare(strict_types=1);

namespace App\Application\Import\EndurainImport\ImportEndurainGear;

use App\Domain\Endurain\Endurain;
use App\Domain\Endurain\EndurainGearTranslator;
use App\Domain\Gear\GearRepository;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Time\Clock\Clock;

/**
 * Imports (or refreshes) a single Endurain gear item by id.
 *
 * This intentionally mirrors the narrow, single-operation philosophy of
 * ImportEndurainActivityCommandHandler rather than Strava's ImportGear,
 * which runs as its own full pass over every gear id referenced across all
 * activities. Endurain has no equivalent batch daemon yet (only the single
 * activity tracer bullet from #12), so gear import here is triggered
 * on-demand: whenever an imported Endurain activity references a gear id,
 * that one gear is fetched and upserted inline. The full batch gear sync
 * (mirroring Strava's ImportGear pass over every referenced gear id) is out
 * of scope here and can be built once an Endurain batch daemon exists.
 */
final readonly class ImportEndurainGearCommandHandler implements CommandHandler
{
    public function __construct(
        private Endurain $endurain,
        private GearRepository $gearRepository,
        private Clock $clock,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof ImportEndurainGear);

        $rawEndurainGear = $this->endurain->getGear($command->getEndurainGearId());
        $gearId = EndurainGearTranslator::toGearId($rawEndurainGear);

        try {
            $gear = $this->gearRepository->find($gearId)
                ->withName(EndurainGearTranslator::toName($rawEndurainGear))
                ->withIsRetired(EndurainGearTranslator::toIsRetired($rawEndurainGear));
            $this->gearRepository->update($gear);
        } catch (EntityNotFound) {
            $gear = EndurainGearTranslator::toGear($rawEndurainGear, $this->clock->getCurrentDateTimeImmutable());
            $this->gearRepository->add($gear);
        }

        $command->getOutput()->writeln(sprintf('  => Imported gear "%s"', $gear->getName()));
    }
}
