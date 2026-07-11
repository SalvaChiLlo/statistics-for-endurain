<?php

declare(strict_types=1);

namespace App\Application\Import\EndurainImport\ImportEndurainGear;

use App\Infrastructure\CQRS\Command\DomainCommand;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ImportEndurainGear extends DomainCommand
{
    public function __construct(
        private OutputInterface $output,
        private int $endurainGearId,
    ) {
    }

    public function getOutput(): OutputInterface
    {
        return $this->output;
    }

    public function getEndurainGearId(): int
    {
        return $this->endurainGearId;
    }
}
