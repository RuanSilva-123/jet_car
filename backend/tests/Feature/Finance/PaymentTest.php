<?php

namespace Tests\Feature\Finance;

use App\Enums\ServiceOrderStatus;
use App\Enums\UserRole;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\ShopSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Recebimentos da OS, situação do pagamento e contas a receber.
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost');
        $this->actingAs(User::factory()->create(['role' => UserRole::Master]));
    }

    private function approvedOrder(int $total = 50000, ServiceOrderStatus $status = ServiceOrderStatus::InProgress): ServiceOrder
    {
        $order = ServiceOrder::factory()->status($status)->create();
        $order->forceFill(['total_cents' => $total, 'labor_total_cents' => $total, 'budget_approved_at' => now(), 'budget_approved_total_cents' => $total])->save();

        return $order;
    }

    public function test_partial_and_full_payment(): void
    {
        $order = $this->approvedOrder();

        $this->postJson("/api/v1/service-orders/{$order->id}/payments", ['method' => 'pix', 'amount_cents' => 20000])
            ->assertOk()
            ->assertJsonPath('data.paid_cents', 20000)
            ->assertJsonPath('data.balance_cents', 30000)
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.payments.0.method_label', 'Pix')
            ->assertJsonPath('data.events.0.type', 'payment_added');

        $this->postJson("/api/v1/service-orders/{$order->id}/payments", ['method' => 'credit_card', 'amount_cents' => 30000, 'installments' => 3])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payments.1.installments', 3);
    }

    public function test_payment_cannot_exceed_balance(): void
    {
        $order = $this->approvedOrder(10000);

        $this->postJson("/api/v1/service-orders/{$order->id}/payments", ['method' => 'cash', 'amount_cents' => 10001])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_cents');
    }

    public function test_installments_only_apply_to_credit_card(): void
    {
        $order = $this->approvedOrder();

        $this->postJson("/api/v1/service-orders/{$order->id}/payments", ['method' => 'pix', 'amount_cents' => 100, 'installments' => 5])
            ->assertOk()
            ->assertJsonPath('data.payments.0.installments', 1);
    }

    public function test_canceled_order_does_not_accept_payment(): void
    {
        $order = $this->approvedOrder(status: ServiceOrderStatus::Canceled);

        $this->postJson("/api/v1/service-orders/{$order->id}/payments", ['method' => 'pix', 'amount_cents' => 100])->assertUnprocessable();
    }

    public function test_only_master_removes_payment(): void
    {
        $order = $this->approvedOrder();
        $paymentId = $this->postJson("/api/v1/service-orders/{$order->id}/payments", ['method' => 'pix', 'amount_cents' => 20000])->json('data.payments.0.id');

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->deleteJson("/api/v1/service-orders/{$order->id}/payments/{$paymentId}")->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => UserRole::Master]));
        $this->deleteJson("/api/v1/service-orders/{$order->id}/payments/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('data.paid_cents', 0)
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.events.0.type', 'payment_removed');
    }

    public function test_receivables_list_open_balances(): void
    {
        $delivered = $this->approvedOrder(40000, ServiceOrderStatus::Delivered);
        $inService = $this->approvedOrder(25000);
        $paid = $this->approvedOrder(10000, ServiceOrderStatus::Delivered);
        $this->postJson("/api/v1/service-orders/{$paid->id}/payments", ['method' => 'cash', 'amount_cents' => 10000])->assertOk();
        $this->postJson("/api/v1/service-orders/{$delivered->id}/payments", ['method' => 'cash', 'amount_cents' => 5000])->assertOk();
        ServiceOrder::factory()->create(); // sem orçamento aprovado: não conta

        $this->getJson('/api/v1/receivables')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $delivered->id)
            ->assertJsonPath('summary.all.balance_cents', 35000 + 25000)
            ->assertJsonPath('summary.delivered.balance_cents', 35000)
            ->assertJsonPath('summary.in_service.count', 1);

        $this->getJson('/api/v1/receivables?scope=in_service')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inService->id);
    }

    public function test_payments_by_period_and_method(): void
    {
        $order = $this->approvedOrder();
        $this->postJson("/api/v1/service-orders/{$order->id}/payments", ['method' => 'pix', 'amount_cents' => 1000])->assertOk();
        $this->postJson("/api/v1/service-orders/{$order->id}/payments", ['method' => 'pix', 'amount_cents' => 2000])->assertOk();
        $this->postJson("/api/v1/service-orders/{$order->id}/payments", ['method' => 'cash', 'amount_cents' => 500, 'paid_at' => now()->subMonths(2)->toDateString()])->assertOk();

        $this->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.total_cents', 3000)
            ->assertJsonPath('summary.by_method.0.method', 'pix')
            ->assertJsonPath('data.0.order.number', $order->number());
    }

    public function test_pdf_footer_uses_shop_timezone(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 20:41:00', 'UTC'));
        $order = $this->approvedOrder(status: ServiceOrderStatus::Delivered);

        $view = view('pdf.report', [
            'order' => $order->load(['customer', 'vehicle', 'items', 'parts', 'payments']),
            'shop' => ShopSettings::get(),
            'logo' => '',
            'generatedAt' => LocalTime::now(),
            'pix' => null,
        ])->render();

        $this->assertStringContainsString('Emitido em 06/10/2026 às 17:41', $view);
    }

    public function test_report_pdf_shows_payments(): void
    {
        $order = $this->approvedOrder(status: ServiceOrderStatus::Delivered);
        $this->postJson("/api/v1/service-orders/{$order->id}/payments", ['method' => 'pix', 'amount_cents' => 20000])->assertOk();

        $this->get("/api/v1/service-orders/{$order->id}/pdf/report")->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
