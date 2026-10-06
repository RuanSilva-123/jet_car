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

    public function test_books_someone_without_registration(): void
    {
        $this->postJson('/api/v1/appointments', ['scheduled_at' => '2026-10-08T09:00:00-03:00', 'duration_minutes' => 60])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);

        $id = $this->book([
            'customer_id' => null,
            'vehicle_id' => null,
            'contact_name' => '  Carlos Mendes ',
            'contact_phone' => '(11) 98888-7777',
            'vehicle_description' => 'Gol prata',
        ]);

        $this->getJson('/api/v1/appointments?from=2026-10-05T00:00:00-03:00&to=2026-10-12T00:00:00-03:00')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.customer', null)
            ->assertJsonPath('data.0.display_name', 'Carlos Mendes')
            ->assertJsonPath('data.0.contact.phone', '11988887777')
            ->assertJsonPath('data.0.vehicle_description', 'Gol prata');
    }

    public function test_check_in_registers_the_guest_as_customer(): void
    {
        $id = $this->book(['customer_id' => null, 'vehicle_id' => null, 'contact_name' => 'Carlos Mendes', 'contact_phone' => '11988887777']);

        // Sem cadastro: precisa escolher ou cadastrar o cliente e o carro
        $this->postJson("/api/v1/appointments/{$id}/check-in", [])->assertUnprocessable()->assertJsonValidationErrors(['customer']);

        $orderId = $this->postJson("/api/v1/appointments/{$id}/check-in", [
            'customer' => ['name' => 'Carlos Mendes', 'phone' => '(11) 98888-7777', 'phone_is_whatsapp' => true],
            'vehicle' => ['type' => 'car', 'brand' => 'Volkswagen', 'model' => 'Gol 1.0', 'plate' => 'abc-1d23'],
            'mileage' => 80000,
        ])->assertCreated()->assertJsonPath('data.appointment.status', 'arrived')->json('data.service_order_id');

        $customer = Customer::where('name', 'Carlos Mendes')->firstOrFail();
        $this->assertSame('11988887777', $customer->phone);
        $order = ServiceOrder::findOrFail($orderId);
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame('ABC1D23', $order->vehicle->plate);
        $this->assertSame('Revisão dos 50 mil km', $order->complaint);
    }

    public function test_check_in_links_guest_to_existing_customer(): void
    {
        $id = $this->book(['customer_id' => null, 'vehicle_id' => null, 'contact_name' => 'Ruan']);

        // Placa já cadastrada em outro cliente
        $this->postJson("/api/v1/appointments/{$id}/check-in", [
            'customer' => ['name' => 'Ruan', 'phone' => '11977776666'],
            'vehicle' => ['type' => 'car', 'brand' => 'X', 'model' => 'Y', 'plate' => $this->vehicle->plate],
        ])->assertUnprocessable()->assertJsonValidationErrors(['vehicle.plate']);

        $this->postJson("/api/v1/appointments/{$id}/check-in", ['customer_id' => $this->customer->id, 'vehicle_id' => $this->vehicle->id])
            ->assertCreated()
            ->assertJsonPath('data.appointment.customer.id', $this->customer->id);
        $this->assertSame(1, Customer::count());
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
