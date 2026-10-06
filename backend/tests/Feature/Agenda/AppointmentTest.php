<?php

namespace Tests\Feature\Agenda;

use App\Enums\ReminderStatus;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\ServiceReminder;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agenda: horários marcados, situação e check-in que vira OS.
 */
class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost');
        $this->actingAs(User::factory()->create());
        $this->customer = Customer::factory()->create(['name' => 'Ruan Silva']);
        $this->vehicle = Vehicle::factory()->for($this->customer)->create();
    }

    private function book(array $overrides = []): int
    {
        return $this->postJson('/api/v1/appointments', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => '2026-10-08T09:00:00-03:00',
            'duration_minutes' => 90,
            'notes' => 'Revisão dos 50 mil km',
            ...$overrides,
        ])->assertCreated()->json('data.id');
    }

    public function test_books_and_lists_by_period(): void
    {
        $this->book();
        $this->book(['scheduled_at' => '2026-11-20T09:00:00-03:00']);

        $this->getJson('/api/v1/appointments?from=2026-10-05T00:00:00-03:00&to=2026-10-12T00:00:00-03:00')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.scheduled_at', '2026-10-08T12:00:00+00:00')
            ->assertJsonPath('data.0.ends_at', '2026-10-08T13:30:00+00:00')
            ->assertJsonPath('data.0.customer.name', 'Ruan Silva')
            ->assertJsonPath('data.0.status', 'scheduled');
    }

    public function test_vehicle_must_belong_to_customer(): void
    {
        $other = Vehicle::factory()->create();

        $this->postJson('/api/v1/appointments', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $other->id,
            'scheduled_at' => '2026-10-08T09:00:00-03:00',
            'duration_minutes' => 60,
        ])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
    }

    public function test_check_in_opens_service_order(): void
    {
        $id = $this->book();

        $orderId = $this->postJson("/api/v1/appointments/{$id}/check-in", ['mileage' => 50210])
            ->assertCreated()
            ->assertJsonPath('data.appointment.status', 'arrived')
            ->json('data.service_order_id');

        $order = ServiceOrder::findOrFail($orderId);
        $this->assertSame('Revisão dos 50 mil km', $order->complaint);
        $this->assertSame(50210, $order->mileage);
        $this->assertSame($this->vehicle->id, $order->vehicle_id);

        // Não faz check-in duas vezes nem muda o agendamento depois
        $this->postJson("/api/v1/appointments/{$id}/check-in")->assertUnprocessable();
        $this->postJson("/api/v1/appointments/{$id}/status", ['status' => 'canceled'])->assertUnprocessable();
    }

    public function test_check_in_without_vehicle_asks_for_it(): void
    {
        $id = $this->book(['vehicle_id' => null]);

        $this->postJson("/api/v1/appointments/{$id}/check-in")->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
        $this->postJson("/api/v1/appointments/{$id}/check-in", ['vehicle_id' => $this->vehicle->id])->assertCreated();
    }

    public function test_status_changes(): void
    {
        $id = $this->book();

        $this->postJson("/api/v1/appointments/{$id}/status", ['status' => 'confirmed'])->assertOk()->assertJsonPath('data.status_label', 'Confirmado');
        $this->postJson("/api/v1/appointments/{$id}/status", ['status' => 'no_show'])->assertOk();
        $this->putJson("/api/v1/appointments/{$id}", [
            'customer_id' => $this->customer->id,
            'scheduled_at' => '2026-10-09T09:00:00-03:00',
            'duration_minutes' => 60,
        ])->assertUnprocessable();
    }

    public function test_booking_from_reminder_marks_it_scheduled(): void
    {
        $reminder = ServiceReminder::create([
            'vehicle_id' => $this->vehicle->id,
            'customer_id' => $this->customer->id,
            'service_name' => 'Troca de óleo',
            'last_done_at' => now()->subMonths(6),
            'status' => ReminderStatus::Pending,
        ]);

        $this->book(['service_reminder_id' => $reminder->id]);

        $this->assertSame(ReminderStatus::Scheduled, $reminder->fresh()->status);
        $this->assertNotNull($reminder->fresh()->contacted_at);
    }
}
