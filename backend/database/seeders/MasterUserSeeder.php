<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class MasterUserSeeder extends Seeder
{
    /**
     * Cria o usuário master a partir de MASTER_EMAIL / MASTER_PASSWORD.
     * Se ele já existir, nada é alterado (a senha não é sobrescrita).
     */
    public function run(): void
    {
        $config = config('jetcar.master');

        if (blank($config['email']) || blank($config['password'])) {
            $this->command?->warn('MASTER_EMAIL e MASTER_PASSWORD não definidos no .env — usuário master não criado.');

            return;
        }

        $user = User::firstOrCreate(
            ['email' => strtolower($config['email'])],
            [
                'name' => $config['name'],
                'password' => $config['password'],
                'role' => UserRole::Master,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $this->command?->info($user->wasRecentlyCreated
            ? "Usuário master criado: {$user->email}"
            : "Usuário master já existe: {$user->email}");
    }
}
