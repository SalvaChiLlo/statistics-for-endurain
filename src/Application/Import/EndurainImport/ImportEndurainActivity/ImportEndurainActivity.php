<?php

declare(strict_types=1);

namespace App\Application\Import\EndurainImport\ImportEndurainActivity;

use App\Infrastructure\CQRS\Command\DomainCommand;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ImportEndurainActivity extends DomainCommand
{
    public function __construct(
        private OutputInterface $output,
        private int $endurainActivityId,
    ) {
    }

    public function getOutput(): OutputInterface
    {
        return $this->output;
    }

    public function getEndurainActivityId(): int
    {
        return $this->endurainActivityId;
    }
}
