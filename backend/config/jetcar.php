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

];
