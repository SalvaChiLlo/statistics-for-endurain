<?php

declare(strict_types=1);

namespace App\Domain\Endurain;

final class EndurainRateLimitExceeded extends \RuntimeException
{
    public static function afterRetries(int $numberOfRetries): self
    {
        return new self(sprintf(
            'Endurain API rate limit (HTTP 429) was still being hit after %d retries. Try again later',
            $numberOfRetries
        ));
    }
}
