<?php

namespace App\Enums;

enum FuelType: string
{
    case Flex = 'flex';
    case Gasoline = 'gasoline';
    case Ethanol = 'ethanol';
    case Diesel = 'diesel';
    case Electric = 'electric';
    case Hybrid = 'hybrid';
    case Cng = 'cng';

    public function label(): string
    {
        return match ($this) {
            self::Flex => 'Flex',
            self::Gasoline => 'Gasolina',
            self::Ethanol => 'Etanol',
            self::Diesel => 'Diesel',
            self::Electric => 'Elétrico',
            self::Hybrid => 'Híbrido',
            self::Cng => 'GNV',
        };
    }
}
