<?php

namespace App\Enums;

enum VehicleType: string
{
    case Car = 'car';
    case Motorcycle = 'motorcycle';
    case Truck = 'truck';

    public function label(): string
    {
        return match ($this) {
            self::Car => 'Carro',
            self::Motorcycle => 'Moto',
            self::Truck => 'Caminhão',
        };
    }

    /** Segmento usado pela API da Tabela FIPE. */
    public function fipePath(): string
    {
        return match ($this) {
            self::Car => 'cars',
            self::Motorcycle => 'motorcycles',
            self::Truck => 'trucks',
        };
    }
}
