<?php

namespace Tests\Unit;

use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LocalTimeTest extends TestCase
{
    public function test_converts_utc_to_shop_timezone(): void
    {
        config(['jetcar.timezone' => 'America/Sao_Paulo']);

        // 01:30 UTC do dia 6 = 22:30 do dia 5 em Brasília
        $this->assertSame('05/10/2026 22:30', LocalTime::format(Carbon::parse('2026-10-06 01:30:00', 'UTC')));
        $this->assertSame('', LocalTime::format(null));
    }
}
