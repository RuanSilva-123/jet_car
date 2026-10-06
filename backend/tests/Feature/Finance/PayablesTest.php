<?php

namespace Tests\Feature\Finance;

use App\Enums\ServiceOrderStatus;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Part;
use App\Models\RecurringBill;
use App\Models\ServiceOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Contas a pagar, despesas fixas, fornecedores e fluxo de caixa.
 */
class PayablesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost');
        // Meio do mês, horário de Brasília
        $this->travelTo(Carbon::parse('2026-10-15 15:00:00', 'America/Sao_Paulo'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Master]));
    }

    private function bill(array $overrides = []): array
    {
        return [
            'description' => 'Aluguel do galpão',
            'category' => 'rent',
            'amount_cents' => 300000,
            'due_date' => '2026-10-20',
            ...$overrides,
        ];
    }

    /** Recebimento de R$ X numa OS, no dia. */
    private function received(int $cents, string $date): void
    {
        $order = ServiceOrder::factory()->status(ServiceOrderStatus::Delivered)->create();
        $order->forceFill(['total_cents' => $cents, 'budget_approved_at' => now()])->save();
        $this->postJson("/api/v1/service-orders/{$order->id}/payments", ['method' => 'pix', 'amount_cents' => $cents, 'paid_at' => $date])->assertOk();
    }

    public function test_supplier_crud_and_unique_document(): void
    {
        $id = $this->postJson('/api/v1/suppliers', ['name' => 'Auto Peças Silva', 'document' => '11.222.333/0001-81', 'phone' => '(51) 3333-4444'])
            ->assertCreated()
            ->assertJsonPath('data.document', '11222333000181')
            ->json('data.id');

        $this->postJson('/api/v1/suppliers', ['name' => 'Outro', 'document' => '11222333000181'])->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->getJson('/api/v1/suppliers?search=silva')->assertJsonPath('data.0.id', $id);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->deleteJson("/api/v1/suppliers/{$id}")->assertForbidden();
    }

    public function test_installments_split_amount_monthly(): void
    {
        $this->postJson('/api/v1/bills', $this->bill(['description' => 'Elevador', 'category' => 'tools', 'amount_cents' => 100000, 'due_date' => '2026-10-31', 'installments' => 3]))
            ->assertCreated()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.amount_cents', 33334)
            ->assertJsonPath('data.1.amount_cents', 33333)
            ->assertJsonPath('data.1.due_date', '2026-11-30')
            ->assertJsonPath('data.2.due_date', '2026-12-31')
            ->assertJsonPath('data.2.installment_number', 3);

        $this->assertSame(100000, (int) Bill::sum('amount_cents'));
    }

    public function test_pay_unpay_and_delete_rules(): void
    {
        $id = $this->postJson('/api/v1/bills', $this->bill())->json('data.0.id');

        $this->postJson("/api/v1/bills/{$id}/pay", ['paid_at' => '2026-10-15', 'payment_method' => 'pix', 'amount_cents' => 305000])
            ->assertOk()
            ->assertJsonPath('data.paid_at', '2026-10-15')
            ->assertJsonPath('data.amount_cents', 305000);

        $this->postJson("/api/v1/bills/{$id}/pay", ['paid_at' => '2026-10-15', 'payment_method' => 'pix'])->assertUnprocessable();
        $this->deleteJson("/api/v1/bills/{$id}")->assertUnprocessable();

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->postJson("/api/v1/bills/{$id}/unpay")->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => UserRole::Master]));
        $this->postJson("/api/v1/bills/{$id}/unpay")->assertOk()->assertJsonPath('data.paid_at', null);
        $this->deleteJson("/api/v1/bills/{$id}")->assertNoContent();
    }

    public function test_list_filters_and_summary(): void
    {
        $this->postJson('/api/v1/bills', $this->bill(['description' => 'Conta de luz', 'category' => 'utilities', 'amount_cents' => 50000, 'due_date' => '2026-10-10']))->assertCreated();
        $this->postJson('/api/v1/bills', $this->bill())->assertCreated();
        $this->postJson('/api/v1/bills', $this->bill(['description' => 'Já paga', 'paid_at' => '2026-10-02', 'payment_method' => 'cash', 'due_date' => '2026-10-02']))->assertCreated();

        $this->getJson('/api/v1/bills?status=overdue')->assertJsonCount(1, 'data')->assertJsonPath('data.0.description', 'Conta de luz')->assertJsonPath('data.0.is_overdue', true);
        $this->getJson('/api/v1/bills?status=open')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.overdue.cents', 50000)
            ->assertJsonPath('summary.open_this_month.cents', 350000)
            ->assertJsonPath('summary.paid_this_month', 300000);
        $this->getJson('/api/v1/bills?status=paid&month=2026-10')->assertJsonCount(1, 'data');
    }

    public function test_recurring_bill_generates_one_bill_per_month(): void
    {
        $this->postJson('/api/v1/recurring-bills', [
            'description' => 'Internet',
            'category' => 'utilities',
            'amount_cents' => 15000,
            'day_of_month' => 31,
            'starts_on' => '2026-09-01',
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.day_of_month', 31);

        // Gerou a conta deste mês na hora (31 de outubro)
        $this->assertSame('2026-10-31', Bill::firstOrFail()->due_date->toDateString());

        $this->artisan('jetcar:recurring-bills')->assertSuccessful();
        $this->assertSame(1, Bill::count());

        // Novembro tem 30 dias: vence no último dia
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00', 'America/Sao_Paulo'));
        $this->artisan('jetcar:recurring-bills')->assertSuccessful();
        $this->assertSame('2026-11-30', Bill::latest('id')->firstOrFail()->due_date->toDateString());
        $this->getJson('/api/v1/recurring-bills')->assertJsonPath('summary.monthly_cents', 15000);
    }

    public function test_inactive_or_ended_recurring_bill_does_not_generate(): void
    {
        RecurringBill::create(['description' => 'Antiga', 'category' => 'rent', 'amount_cents' => 100, 'day_of_month' => 5, 'starts_on' => '2026-01-01', 'ends_on' => '2026-09-30', 'is_active' => true]);
        RecurringBill::create(['description' => 'Pausada', 'category' => 'rent', 'amount_cents' => 100, 'day_of_month' => 5, 'starts_on' => '2026-01-01', 'is_active' => false]);

        $this->artisan('jetcar:recurring-bills');

        $this->assertSame(0, Bill::count());
    }

    public function test_cash_flow_month(): void
    {
        $this->putJson('/api/v1/settings/finance', ['opening_balance_cents' => 1000000, 'opening_date' => '2026-10-01'])->assertOk();
        $this->received(200000, '2026-10-05');
        $this->postJson('/api/v1/bills', $this->bill(['description' => 'Fornecedor', 'category' => 'parts', 'amount_cents' => 80000, 'due_date' => '2026-10-08', 'paid_at' => '2026-10-08', 'payment_method' => 'pix']))->assertCreated();
        $this->postJson('/api/v1/bills', $this->bill(['description' => 'Atrasada', 'amount_cents' => 10000, 'due_date' => '2026-10-12']))->assertCreated();
        $this->postJson('/api/v1/bills', $this->bill(['amount_cents' => 300000, 'due_date' => '2026-10-20']))->assertCreated();

        $response = $this->getJson('/api/v1/cash-flow?month=2026-10')
            ->assertOk()
            ->assertJsonPath('data.opening_balance_cents', 1000000)
            ->assertJsonPath('data.summary.received_cents', 200000)
            ->assertJsonPath('data.summary.paid_cents', 80000)
            ->assertJsonPath('data.summary.current_balance_cents', 1120000)
            ->assertJsonPath('data.summary.to_pay_cents', 310000)
            ->assertJsonPath('data.summary.overdue_count', 1)
            ->assertJsonPath('data.summary.projected_end_cents', 810000)
            ->assertJsonCount(31, 'data.days');

        $days = collect($response->json('data.days'))->keyBy('date');
        $this->assertSame(10000, $days['2026-10-15']['planned_out_cents']); // vencida cai em "hoje"
        $this->assertSame(300000, $days['2026-10-20']['planned_out_cents']);
        $this->assertTrue($days['2026-10-20']['projected']);
        $this->assertSame(810000, $days['2026-10-31']['balance_cents']);

        // Mês seguinte começa com o saldo realizado de outubro
        $this->getJson('/api/v1/cash-flow?month=2026-11')->assertJsonPath('data.opening_balance_cents', 1120000);
        $this->get('/api/v1/cash-flow?month=2026-10&format=csv')->assertOk();
    }

    public function test_stock_purchase_creates_bill(): void
    {
        $supplier = Supplier::create(['name' => 'Auto Peças Silva']);
        $part = Part::factory()->create(['name' => 'Filtro de óleo', 'unit' => 'un']);

        $this->postJson("/api/v1/parts/{$part->id}/stock", [
            'type' => 'entry',
            'quantity' => 10,
            'unit_cost_cents' => 1850,
            'create_bill' => true,
            'bill_due_date' => '2026-11-10',
            'bill_supplier_id' => $supplier->id,
        ])->assertOk()->assertJsonPath('data.stock_quantity', 10);

        $bill = Bill::firstOrFail();
        $this->assertSame(18500, $bill->amount_cents);
        $this->assertSame('parts', $bill->category->value);
        $this->assertSame($supplier->id, $bill->supplier_id);
        $this->assertStringContainsString('Filtro de óleo', $bill->description);
    }

    public function test_mechanic_cannot_touch_payables(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Mechanic]));

        $this->getJson('/api/v1/bills')->assertForbidden();
        $this->getJson('/api/v1/cash-flow')->assertForbidden();
        $this->getJson('/api/v1/suppliers')->assertForbidden();

        $part = Part::factory()->create();
        $this->postJson("/api/v1/parts/{$part->id}/stock", ['type' => 'entry', 'quantity' => 1, 'unit_cost_cents' => 100, 'create_bill' => true, 'bill_due_date' => '2026-11-10'])->assertForbidden();
    }

    public function test_dashboard_shows_bills_and_cash_balance(): void
    {
        $this->putJson('/api/v1/settings/finance', ['opening_balance_cents' => 50000, 'opening_date' => '2026-10-01'])->assertOk();
        $this->postJson('/api/v1/bills', $this->bill(['amount_cents' => 7000, 'due_date' => '2026-10-10']))->assertCreated();
        $this->postJson('/api/v1/bills', $this->bill(['amount_cents' => 3000, 'due_date' => '2026-10-18']))->assertCreated();

        $this->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.finance.bills_overdue_cents', 7000)
            ->assertJsonPath('data.finance.bills_due_soon_cents', 3000)
            ->assertJsonPath('data.finance.cash_balance_cents', 50000);
    }
}
