<?php

namespace App\Enums;

/** Categorias da mão de obra da oficina (organização e filtro). */
enum ServiceCategory: string
{
    case Maintenance = 'maintenance';
    case Engine = 'engine';
    case Suspension = 'suspension';
    case Brakes = 'brakes';
    case Transmission = 'transmission';
    case Steering = 'steering';
    case Cooling = 'cooling';
    case Electrical = 'electrical';
    case AirConditioning = 'air_conditioning';
    case Exhaust = 'exhaust';
    case Tires = 'tires';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Maintenance => 'Revisão e manutenção',
            self::Engine => 'Motor',
            self::Suspension => 'Suspensão',
            self::Brakes => 'Freios',
            self::Transmission => 'Embreagem e transmissão',
            self::Steering => 'Direção',
            self::Cooling => 'Arrefecimento',
            self::Electrical => 'Elétrica',
            self::AirConditioning => 'Ar-condicionado',
            self::Exhaust => 'Escapamento',
            self::Tires => 'Pneus e rodas',
            self::Other => 'Outros',
        };
    }
}
