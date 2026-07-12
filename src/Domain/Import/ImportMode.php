<?php

declare(strict_types=1);

namespace App\Domain\Import;

enum ImportMode: string
{
    case FILES = 'files';

    /**
     * FILES is currently the only case, so this is always true. Kept as a method
     * (rather than inlined at call sites) since it reads better at usage sites and
     * is a natural extension point should a second case ever be reintroduced.
     */
    public function isFiles(): bool
    {
        return true;
    }

    public static function fromServerVar(): self
    {
        return self::from($_SERVER['IMPORT_MODE']);
    }
}
