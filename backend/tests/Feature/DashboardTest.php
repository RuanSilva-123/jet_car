<?php

namespace Tests\Feature;

use App\Enums\ServiceOrderStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Part;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Indicadores do dashboard.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost');
        $this->travelTo('2026-10-20 15:00:00');
    }

    public function test_dashboard_indicators(): void
    {
        $this->actingAs(User::factory()->create());

        ServiceOrder::factory()->status(ServiceOrderStatus::InProgress)->create(['expected_at' => '2026-10-18']);   // atrasada
        ServiceOrder::factory()->status(ServiceOrderStatus::InProgress)->create(['expected_at' => '2026-10-25']);
        $stale = ServiceOrder::factory()->status(ServiceOrderStatus::WaitingApproval)->create();
        $stale->forceFill(['budget_sent_at' => now()->subDays(5)])->save();
        $fresh = ServiceOrder::factory()->status(ServiceOrderStatus::WaitingApproval)->create();
        $fresh->forceFill(['budget_sent_at' => now()->subDay()])->save();
        ServiceOrder::factory()->status(ServiceOrderStatus::Completed)->create();
        foreach ([[30000, '2026-10-05'], [10000, '2026-10-10'], [50000, '2026-09-10']] as [$total, $date]) {
            $order = ServiceOrder::factory()->status(ServiceOrderStatus::Delivered)->create();
            $order->forceFill(['total_cents' => $total, 'delivered_at' => $date.' 12:00:00', 'budget_approved_at' => $date])->save();
        }
        Part::factory()->stock(1, 5)->create();
        $appointment = new Appointment(['customer_id' => $stale->customer_id, 'scheduled_at' => now()->addHour(), 'duration_minutes' => 60]);
        $appointment->status = 'scheduled';
        $appointment->save();

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.active_total', 5)
            ->assertJsonPath('data.overdue.count', 1)
            ->assertJsonPath('data.overdue.items.0.date', '2026-10-18')
            ->assertJsonPath('data.stale_budgets.count', 1)
            ->assertJsonPath('data.stale_budgets.items.0.id', $stale->id)
            ->assertJsonPath('data.ready_for_pickup.count', 1)
            ->assertJsonPath('data.finance.revenue_cents', 40000)
            ->assertJsonPath('data.finance.delivered_count', 2)
            ->assertJsonPath('data.finance.average_ticket_cents', 20000)
            ->assertJsonPath('data.finance.previous_revenue_cents', 50000)
            ->assertJsonPath('data.finance.receivable_cents', 90000)
            ->assertJsonPath('data.appointments_today.count', 1)
            ->assertJsonPath('data.low_stock.count', 1);

        $this->getJson('/api/v1/dashboard?budget_days=1')->assertJsonPath('data.stale_budgets.count', 2);
    }

    public function test_mechanic_does_not_see_finance(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Mechanic]));

        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.finance', null);
    }
}
