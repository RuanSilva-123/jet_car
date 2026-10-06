<?php

namespace Tests\Feature\Reminders;

use App\Enums\ServiceOrderStatus;
use App\Models\LaborService;
use App\Models\ServiceOrder;
use App\Models\ServiceReminder;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lembretes de revisão: intervalo no serviço, geração pelo scheduler e contato com o cliente.
 */
class ServiceReminderTest extends TestCase
{
    use RefreshDatabase;

    private LaborService $oil;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost');
        $this->actingAs(User::factory()->create());
        $this->oil = LaborService::factory()->create(['name' => 'Troca de óleo', 'reminder_months' => 6, 'reminder_km' => 10000]);
        $this->vehicle = Vehicle::factory()->create(['mileage' => 50000]);
    }

    /** OS entregue com o serviço feito em $doneAt, com $mileage na entrada. */
    private function deliveredOilChange(string $doneAt, int $mileage): ServiceOrder
    {
        $order = ServiceOrder::factory()->for($this->vehicle)->status(ServiceOrderStatus::Delivered)->create([
            'customer_id' => $this->vehicle->customer_id,
            'mileage' => $mileage,
        ]);
        $order->forceFill(['delivered_at' => $doneAt])->save();
        $item = $order->items()->create(['labor_service_id' => $this->oil->id, 'name' => $this->oil->name, 'position' => 0]);
        $item->forceFill(['is_done' => true, 'done_at' => $doneAt])->save();

        return $order;
    }

    public function test_labor_service_keeps_reminder_interval(): void
    {
        $this->postJson('/api/v1/labor-services', [
            'name' => 'Troca de correia',
            'category' => 'engine',
            'is_active' => true,
            'reminder_months' => 48,
            'reminder_km' => '60.000',
        ])->assertCreated()->assertJsonPath('data.reminder_km', 60000)->assertJsonPath('data.reminder_months', 48);
    }

    public function test_scheduler_creates_reminder_when_due_by_date(): void
    {
        $this->deliveredOilChange(now()->subMonths(6)->addDays(5)->toDateString(), 45000);

        $this->artisan('jetcar:service-reminders')->assertSuccessful();

        $reminder = ServiceReminder::firstOrFail();
        $this->assertSame('Troca de óleo', $reminder->service_name);
        $this->assertSame(55000, $reminder->due_mileage);

        // Rodar de novo não duplica
        $this->artisan('jetcar:service-reminders')->assertSuccessful();
        $this->assertSame(1, ServiceReminder::count());
    }

    public function test_reminder_by_mileage(): void
    {
        $this->deliveredOilChange(now()->subMonth()->toDateString(), 40000);
        $this->vehicle->update(['mileage' => 49600]);

        $this->artisan('jetcar:service-reminders');

        $this->assertSame(1, ServiceReminder::count());
    }

    public function test_not_due_yet_is_ignored(): void
    {
        $this->deliveredOilChange(now()->subMonth()->toDateString(), 49000);

        $this->artisan('jetcar:service-reminders');

        $this->assertSame(0, ServiceReminder::count());
    }

    public function test_newer_execution_closes_old_reminder(): void
    {
        $this->deliveredOilChange(now()->subMonths(7)->toDateString(), 30000);
        $this->artisan('jetcar:service-reminders');
        $this->assertSame('pending', ServiceReminder::firstOrFail()->status->value);

        $this->deliveredOilChange(now()->subDays(3)->toDateString(), 50000);
        $this->artisan('jetcar:service-reminders');

        $this->assertSame('done', ServiceReminder::firstOrFail()->status->value);
        $this->assertSame(1, ServiceReminder::count());
    }

    public function test_list_and_contact_flow(): void
    {
        $this->deliveredOilChange(now()->subMonths(7)->toDateString(), 30000);
        $this->postJson('/api/v1/service-reminders/refresh')->assertOk()->assertJsonPath('data.created', 1);

        $id = $this->getJson('/api/v1/service-reminders')
            ->assertOk()
            ->assertJsonPath('summary.pending', 1)
            ->assertJsonPath('data.0.is_overdue', true)
            ->assertJsonPath('data.0.vehicle.plate', $this->vehicle->plate)
            ->json('data.0.id');

        $this->patchJson("/api/v1/service-reminders/{$id}", ['status' => 'contacted', 'notes' => 'Vai ligar semana que vem'])
            ->assertOk()
            ->assertJsonPath('data.status_label', 'Contatado')
            ->assertJsonPath('data.contacted_by', auth()->user()->name);

        $this->patchJson("/api/v1/service-reminders/{$id}", ['status' => 'dismissed'])->assertOk();
        $this->getJson('/api/v1/service-reminders')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/service-reminders?status=dismissed')->assertJsonCount(1, 'data');
    }
}
