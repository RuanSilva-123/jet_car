<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Limite por IP na rota de login (o limite por e-mail fica no LoginRequest)
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        // Consultas FIPE/CEP: protege a cota diária das APIs externas
        RateLimiter::for('lookups', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // Dados da oficina (cabeçalho dos PDFs): só o master altera
        Gate::define('manage-settings', fn (User $user) => $user->isMaster());

        // Financeiro (pagamentos, contas a receber, relatórios): toda a equipe, menos o mecânico
        Gate::define('manage-finance', fn (User $user) => $user->canManageFinance());

        // Remover um recebimento já lançado (estorno): só o master
        Gate::define('delete-payment', fn (User $user) => $user->isMaster());

        // Regra padrão de senha das contas do painel
        Password::defaults(fn () => Password::min(8)->letters()->numbers());
    }
}
