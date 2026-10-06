<?php

use App\Http\Controllers\Api\V1\AddressLookupController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\LaborServiceController;
use App\Http\Controllers\Api\V1\MechanicController;
use App\Http\Controllers\Api\V1\PartController;
use App\Http\Controllers\Api\V1\PlateLookupController;
use App\Http\Controllers\Api\V1\ServiceOrderController;
use App\Http\Controllers\Api\V1\ServiceOrderPaymentController;
use App\Http\Controllers\Api\V1\ServiceOrderPdfController;
use App\Http\Controllers\Api\V1\ShopSettingsController;
use App\Http\Controllers\Api\V1\VehicleHistoryController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VehicleCatalogController;
use Illuminate\Support\Facades\Route;

/*
| Todas as rotas ficam sob /api/v1.
| Públicas: apenas o login. Não existe rota de cadastro: contas são criadas pelo usuário master.
| Todo o resto PRECISA estar dentro do grupo autenticado abaixo.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        // Gestão de contas (somente master — ver UserPolicy)
        Route::apiResource('users', UserController::class);

        // Mecânicos ativos (atribuição dos serviços da OS)
        Route::get('mechanics', MechanicController::class)->name('mechanics.index');

        // Clientes e seus veículos (exclusão só para o master — ver CustomerPolicy)
        Route::apiResource('customers', CustomerController::class);

        // Ordens de serviço (sem exclusão: OS que não vai acontecer é cancelada)
        Route::apiResource('service-orders', ServiceOrderController::class)->except('destroy');
        Route::prefix('service-orders/{service_order}')->name('service-orders.')->controller(ServiceOrderController::class)->group(function () {
            Route::post('status', 'changeStatus')->name('status');
            Route::post('budget/send', 'sendBudget')->name('budget.send');
            Route::post('budget/approve', 'approveBudget')->name('budget.approve');
            Route::post('budget/reject', 'rejectBudget')->name('budget.reject');
            Route::post('notes', 'addNote')->name('notes');
            Route::post('items', 'addItem')->name('items.store');
            Route::patch('items/{item}', 'toggleItem')->whereNumber('item')->name('items.toggle');
            Route::put('items/{item}/mechanic', 'assignMechanic')->whereNumber('item')->name('items.mechanic');
            Route::delete('items/{item}', 'removeItem')->whereNumber('item')->name('items.destroy');
            Route::post('parts', 'addPart')->name('parts.store');
            Route::delete('parts/{part}', 'removePart')->whereNumber('part')->name('parts.destroy');
        });

        // Recebimentos da OS (registrar: financeiro; remover: só master)
        Route::post('service-orders/{service_order}/payments', [ServiceOrderPaymentController::class, 'store'])->name('service-orders.payments.store');
        Route::delete('service-orders/{service_order}/payments/{payment}', [ServiceOrderPaymentController::class, 'destroy'])
            ->whereNumber('payment')
            ->name('service-orders.payments.destroy');

        // Financeiro: contas a receber e recebimentos do período
        Route::get('receivables', [FinanceController::class, 'receivables'])->name('receivables.index');
        Route::get('payments', [FinanceController::class, 'payments'])->name('payments.index');

        // PDFs da OS: orçamento (para o cliente aprovar) e comprovante do serviço realizado
        Route::get('service-orders/{service_order}/pdf/{document}', ServiceOrderPdfController::class)
            ->whereIn('document', ['budget', 'report'])
            ->name('service-orders.pdf');

        // Dados da oficina (cabeçalho dos PDFs); alterar é só para o master
        Route::get('settings/shop', [ShopSettingsController::class, 'show'])->name('settings.shop.show');
        Route::put('settings/shop', [ShopSettingsController::class, 'update'])->name('settings.shop.update');

        // Histórico de serviços do veículo
        Route::get('vehicles/{vehicle}/history', VehicleHistoryController::class)->whereNumber('vehicle')->name('vehicles.history');

        // Estoque de peças (exclusão só para o master — ver PartPolicy)
        Route::post('parts/{part}/stock', [PartController::class, 'moveStock'])->name('parts.stock');
        Route::get('parts/{part}/movements', [PartController::class, 'movements'])->name('parts.movements');
        Route::apiResource('parts', PartController::class);

        // Catálogo de mão de obra (exclusão só para o master — ver LaborServicePolicy)
        Route::post('labor-services/suggestions', [LaborServiceController::class, 'importSuggestions'])
            ->name('labor-services.suggestions');
        Route::apiResource('labor-services', LaborServiceController::class);

        // Consultas a serviços externos (com cache no backend)
        Route::middleware('throttle:lookups')->group(function () {
            Route::prefix('vehicle-catalog/{type}')
                ->name('vehicle-catalog.')
                ->controller(VehicleCatalogController::class)
                ->group(function () {
                    Route::get('brands', 'brands')->name('brands');
                    // Fluxo: marca → ano/combustível → modelos daquele ano
                    Route::get('brands/{brand}/years', 'years')->whereNumber('brand')->name('years');
                    Route::get('brands/{brand}/years/{year}/models', 'models')
                        ->whereNumber('brand')
                        ->where('year', '[0-9]{4,5}-[0-9]')
                        ->name('models');
                });

            // Dados do veículo pela placa (antiga ABC1234 ou Mercosul ABC1D23)
            Route::get('plate-lookup', [PlateLookupController::class, 'status'])->name('plate-lookup.status');
            Route::get('plate-lookup/{plate}', [PlateLookupController::class, 'show'])
                ->where('plate', '[A-Za-z]{3}[0-9][A-Za-z0-9][0-9]{2}')
                ->name('plate-lookup.show');

            Route::get('address-lookup/{zipCode}', AddressLookupController::class)
                ->where('zipCode', '[0-9]{8}')
                ->name('address-lookup');
        });
    });
});
