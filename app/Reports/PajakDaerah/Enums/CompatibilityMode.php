<?php

namespace App\Reports\PajakDaerah\Enums;

enum CompatibilityMode: string
{
    case Compatibility = 'compatibility';
    case Corrected = 'corrected';

    public static function fromInput(?string $value): self
    {
        return $value === 'corrected' ? self::Corrected : self::Compatibility;
    }
}