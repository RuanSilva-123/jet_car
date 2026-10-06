<?php

namespace Tests\Feature\Reports;

use App\Enums\ServiceOrderStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\LaborService;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Relatórios com exportação CSV.
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost');
        $this->actingAs(User::factory()->create());
        $this->customer = Customer::factory()->create(['name' => 'Ruan Silva']);
        $this->travelTo('2026-10-20 15:00:00');
    }

    /** OS entregue em $deliveredAt (horário de Brasília) com um serviço de $price. */
    private function delivered(string $deliveredAt, int $price, ?LaborService $service = null, ?User $mechanic = null, ?Customer $customer = null): ServiceOrder
    {
        $customer ??= $this->customer;
        $vehicle = Vehicle::factory()->for($customer)->create();
        $order = ServiceOrder::factory()->for($vehicle)->status(ServiceOrderStatus::Delivered)->create(['customer_id' => $customer->id]);
        $when = now()->parse($deliveredAt, 'America/Sao_Paulo')->utc();
        $order->forceFill(['delivered_at' => $when, 'labor_total_cents' => $price, 'total_cents' => $price])->save();
        $service ??= LaborService::factory()->create(['name' => 'Alinhamento']);
        $item = $order->items()->create(['labor_service_id' => $service->id, 'name' => $service->name, 'price_cents' => $price, 'position' => 0]);
        $item->forceFill(['is_done' => true, 'done_at' => $when, 'mechanic_id' => $mechanic?->id])->save();

        return $order;
    }

    public function test_revenue_by_day_uses_shop_timezone(): void
    {
        $this->delivered('2026-10-05 10:00', 10000);
        $this->delivered('2026-10-05 22:30', 20000); // 01:30 UTC do dia 6: continua sendo dia 5 aqui
        $this->delivered('2026-09-30 10:00', 99900); // fora do período

        $this->getJson('/api/v1/reports/revenue?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.period', '2026-10-05')
            ->assertJsonPath('data.rows.0.orders', 2)
            ->assertJsonPath('data.rows.0.average_cents', 15000)
            ->assertJsonPath('data.totals.total_cents', 30000);
    }

    public function test_revenue_by_month_and_csv_export(): void
    {
        $this->delivered('2026-09-10 10:00', 12345);
        $this->delivered('2026-10-10 10:00', 10000);

        $this->getJson('/api/v1/reports/revenue?from=2026-09-01&to=2026-10-31&group=month')
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.rows.0.period', '2026-09-01');

        $response = $this->get('/api/v1/reports/revenue?from=2026-09-01&to=2026-10-31&group=month&format=csv')->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Mês;"OS entregues"', $csv);
        $this->assertStringContainsString('09/2026;1;123,45', $csv);
        $this->assertStringContainsString('Total;2;223,45', $csv);
    }

    public function test_top_services(): void
    {
        $oil = LaborService::factory()->create(['name' => 'Troca de óleo']);
        $align = LaborService::factory()->create(['name' => 'Alinhamento']);
        $this->delivered('2026-10-02 10:00', 8000, $oil);
        $this->delivered('2026-10-03 10:00', 9000, $oil);
        $this->delivered('2026-10-04 10:00', 30000, $align);

        $this->getJson('/api/v1/reports/services?from=2026-10-01&to=2026-10-31')
            ->assertJsonPath('data.rows.0.name', 'Troca de óleo')
            ->assertJsonPath('data.rows.0.count', 2)
            ->assertJsonPath('data.rows.0.average_cents', 8500)
            ->assertJsonPath('data.rows.1.share', 63.8);
    }

    public function test_returning_customers(): void
    {
        $this->delivered('2026-06-01 10:00', 5000);       // já tinha vindo antes
        $this->delivered('2026-10-02 10:00', 7000);
        $this->delivered('2026-10-03 10:00', 4000, customer: Customer::factory()->create(['name' => 'Novo Cliente']));

        $this->getJson('/api/v1/reports/customers?from=2026-10-01&to=2026-10-31')
            ->assertJsonPath('data.summary.customers', 2)
            ->assertJsonPath('data.summary.returning', 1)
            ->assertJsonPath('data.summary.returning_rate', 50);

        $this->getJson('/api/v1/reports/customers?from=2026-10-01&to=2026-10-31&only_returning=1')
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.name', 'Ruan Silva')
            ->assertJsonPath('data.rows.0.lifetime_orders', 2)
            ->assertJsonPath('data.rows.0.first_visit', '2026-06-01');
    }

    public function test_mechanic_production(): void
    {
        $bruno = User::factory()->create(['name' => 'Bruno', 'role' => UserRole::Mechanic]);
        $this->delivered('2026-10-02 10:00', 8000, mechanic: $bruno);
        $this->delivered('2026-10-03 10:00', 2000, mechanic: $bruno);
        $this->delivered('2026-10-04 10:00', 1000);

        $this->getJson('/api/v1/reports/mechanics?from=2026-10-01&to=2026-10-31')
            ->assertJsonPath('data.rows.0.name', 'Bruno')
            ->assertJsonPath('data.rows.0.services', 2)
            ->assertJsonPath('data.rows.0.labor_cents', 10000)
            ->assertJsonPath('data.rows.1.name', 'Sem responsável')
            ->assertJsonPath('data.totals.services', 3);
    }

    public function test_default_period_is_current_month_and_validation(): void
    {
        $this->getJson('/api/v1/reports/revenue')->assertOk()->assertJsonPath('data.from', '2026-10-01')->assertJsonPath('data.to', '2026-10-20');
        $this->getJson('/api/v1/reports/revenue?from=2026-10-10&to=2026-10-01')->assertUnprocessable();
        $this->getJson('/api/v1/reports/unknown')->assertNotFound();
    }
}
