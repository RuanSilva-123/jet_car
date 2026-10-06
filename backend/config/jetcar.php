<?php

return [

    /*
    | Usuário master criado pelo MasterUserSeeder. É a única conta que nasce
    | no sistema; as demais são criadas por ele dentro do painel.
    */

    'master' => [
        'name' => env('MASTER_NAME', 'Administrador Master'),
        'email' => env('MASTER_EMAIL'),
        'password' => env('MASTER_PASSWORD'),
    ],

    /*
    | Fuso usado nos textos gerados pelo servidor (ex.: "agendado para 08/10 às 9h").
    | O banco guarda tudo em UTC; o painel converte para o horário do navegador.
    */

    'timezone' => env('DISPLAY_TIMEZONE', 'America/Sao_Paulo'),

    /*
    | Lembretes de revisão: entram na lista com esta antecedência (dias antes da data
    | ou km antes da quilometragem prevista).
    */

    'reminders' => [
        'lead_days' => (int) env('REMINDER_LEAD_DAYS', 15),
        'lead_km' => (int) env('REMINDER_LEAD_KM', 500),
    ],

];
