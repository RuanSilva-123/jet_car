<?php

use App\Services\Reminders\ServiceReminderGenerator;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Lembretes de revisão: monta a lista de clientes a contatar (troca de óleo, revisão...).
| Roda no container "scheduler" (php artisan schedule:work).
*/
Artisan::command('jetcar:service-reminders', function (ServiceReminderGenerator $generator) {
    $result = $generator->run();
    $this->info("Lembretes criados: {$result['created']}. Encerrados (serviço refeito): {$result['closed']}.");
})->purpose('Gera a lista de clientes a contatar para revisão');

Schedule::command('jetcar:service-reminders')->dailyAt('06:00')->withoutOverlapping()->onOneServer();
