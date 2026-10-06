<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * O banco guarda data e hora em UTC; o que vai para o cliente (PDF, textos) usa o horário da
 * oficina (config jetcar.timezone). Só para timestamps: campos só de data (previsão de entrega,
 * data do pagamento) não têm fuso e não devem passar por aqui.
 */
final class LocalTime
{
    public static function of(?CarbonInterface $moment): ?Carbon
    {
        return $moment === null ? null : Carbon::instance($moment)->copy()->timezone((string) config('jetcar.timezone'));
    }

    public static function format(?CarbonInterface $moment, string $format = 'd/m/Y H:i'): string
    {
        return self::of($moment)?->format($format) ?? '';
    }

    public static function now(): Carbon
    {
        return now()->timezone((string) config('jetcar.timezone'));
    }
}
