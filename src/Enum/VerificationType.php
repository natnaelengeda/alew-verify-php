<?php

declare(strict_types=1);

namespace App\Enum;

enum VerificationType: string
{
    case Telebirr = 'telebirr';
    case Cbe = 'cbe';

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom(strtolower($value));
    }
}
