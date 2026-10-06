<?php

namespace Tests\Feature\Finance;

use App\Enums\ServiceOrderStatus;
use App\Enums\UserRole;
use App\Models\LaborService;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Support\BudgetLink;
use App\Support\ShopSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pix copia e cola + QR Code: cadastro da chave, cobrança da OS, link público e comprovante.
 */
class PixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost');
        $this->actingAs(User::factory()->create(['role' => UserRole::Master]));
    }

    private function configurePix(): void
    {
        ShopSettings::save(['pix_key_type' => 'cnpj', 'pix_key' => '11222333000181', 'pix_beneficiary' => 'JetCar', 'pix_city' => 'Porto Alegre']);
    }

    private function approvedOrder(int $total = 30000, ServiceOrderStatus $status = ServiceOrderStatus::InProgress): ServiceOrder
    {
        $order = ServiceOrder::factory()->status($status)->create();
        $order->forceFill(['total_cents' => $total, 'labor_total_cents' => $total, 'budget_approved_at' => now(), 'budget_approved_total_cents' => $total])->save();

        return $order;
    }

    public function test_master_saves_pix_key_normalized(): void
    {
        $this->putJson('/api/v1/settings/shop', [
            'name' => 'JetCar',
            'budget_validity_days' => 7,
            'pix_key_type' => 'phone',
            'pix_key' => '(51) 99999-8888',
            'pix_city' => 'Porto Alegre',
        ])->assertOk()
            ->assertJsonPath('data.pix_key', '+5551999998888')
            ->assertJsonPath('data.pix_ready', true)
            ->assertJsonPath('options.pix_key_types.cnpj', 'CNPJ');
    }

    public function test_invalid_key_for_type_is_rejected(): void
    {
        $this->putJson('/api/v1/settings/shop', [
            'name' => 'JetCar',
            'budget_validity_days' => 7,
            'pix_key_type' => 'cpf',
            'pix_key' => '111.111.111-11',
            'pix_city' => 'Porto Alegre',
        ])->assertUnprocessable()->assertJsonValidationErrors('pix_key');
    }

    public function test_saving_other_fields_keeps_pix_key(): void
    {
        $this->configurePix();

        $this->putJson('/api/v1/settings/shop', ['name' => 'JetCar Nova', 'budget_validity_days' => 10, 'pix_key_type' => 'cnpj', 'pix_key' => '11222333000181', 'pix_city' => 'Porto Alegre'])->assertOk();
        $this->assertSame('11222333000181', ShopSettings::get()['pix_key']);
    }

    public function test_order_pix_charges_open_balance(): void
    {
        $this->configurePix();
        $order = $this->approvedOrder();
        $this->postJson("/api/v1/service-orders/{$order->id}/payments", ['method' => 'cash', 'amount_cents' => 10000])->assertOk();

        $response = $this->getJson("/api/v1/service-orders/{$order->id}/pix")
            ->assertOk()
            ->assertJsonPath('data.amount_cents', 20000)
            ->assertJsonPath('data.txid', 'OS'.$order->number());

        $this->assertStringContainsString('5406200.00', $response->json('data.payload'));
        $this->assertStringStartsWith('data:image/png;base64,', $response->json('data.qr_code'));

        $this->getJson("/api/v1/service-orders/{$order->id}/pix?amount_cents=5000")->assertJsonPath('data.amount_cents', 5000);
        $this->getJson("/api/v1/service-orders/{$order->id}/pix?amount_cents=99999")->assertUnprocessable();
    }

    public function test_order_pix_without_key_or_balance(): void
    {
        $order = $this->approvedOrder();
        $this->getJson("/api/v1/service-orders/{$order->id}/pix")->assertNotFound();

        $this->configurePix();
        $paid = $this->approvedOrder(1000);
        $this->postJson("/api/v1/service-orders/{$paid->id}/payments", ['method' => 'pix', 'amount_cents' => 1000])->assertOk();
        $this->getJson("/api/v1/service-orders/{$paid->id}/pix")->assertNotFound();
    }

    public function test_mechanic_cannot_generate_pix(): void
    {
        $this->configurePix();
        $order = $this->approvedOrder();

        $this->actingAs(User::factory()->create(['role' => UserRole::Mechanic]));
        $this->getJson("/api/v1/service-orders/{$order->id}/pix")->assertForbidden();
    }

    public function test_public_budget_shows_pix_only_after_approval(): void
    {
        $this->configurePix();
        $order = ServiceOrder::factory()->create();
        $this->postJson("/api/v1/service-orders/{$order->id}/items", ['labor_service_id' => LaborService::factory()->create()->id])->assertOk();
        $item = $order->items()->first();
        $this->putJson("/api/v1/service-orders/{$order->id}", ['items' => [['id' => $item->id, 'price_cents' => 15000]]])->assertOk();
        $this->postJson("/api/v1/service-orders/{$order->id}/budget/send")->assertOk();
        $this->app['auth']->forgetGuards();
        $path = '/api/v1/public/budgets/'.BudgetLink::make($order->fresh())['token'];

        $this->getJson($path)->assertJsonPath('data.pix', null);

        $this->postJson("{$path}/approve", ['total_cents' => 15000])
            ->assertOk()
            ->assertJsonPath('data.pix.amount_cents', 15000)
            ->assertJsonPath('data.order.balance_cents', 15000);
    }

    public function test_receipt_pdf_with_pix(): void
    {
        $this->configurePix();
        $order = $this->approvedOrder(status: ServiceOrderStatus::Delivered);

        $this->get("/api/v1/service-orders/{$order->id}/pdf/report")->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
