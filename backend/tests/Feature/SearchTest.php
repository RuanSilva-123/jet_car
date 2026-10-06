<?php

namespace Tests\Feature;

use App\Enums\ServiceOrderStatus;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Busca global (Ctrl+K).
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost');
        $this->actingAs(User::factory()->create());
    }

    public function test_plate_leads_to_vehicle_customer_and_active_order(): void
    {
        $customer = Customer::factory()->create(['name' => 'Ruan Silva']);
        $vehicle = Vehicle::factory()->for($customer)->create(['plate' => 'RUA1N23']);
        ServiceOrder::factory()->for($vehicle)->status(ServiceOrderStatus::Delivered)->create(['customer_id' => $customer->id]);
        $active = ServiceOrder::factory()->for($vehicle)->status(ServiceOrderStatus::InProgress)->create(['customer_id' => $customer->id]);

        $this->getJson('/api/v1/search?q=rua-1n23')
            ->assertOk()
            ->assertJsonPath('data.vehicles.0.id', $vehicle->id)
            ->assertJsonPath('data.vehicles.0.customer.name', 'Ruan Silva')
            ->assertJsonPath('data.vehicles.0.active_order.id', $active->id)
            ->assertJsonCount(2, 'data.orders');
    }

    public function test_finds_customer_by_name_and_phone_and_order_by_number(): void
    {
        $customer = Customer::factory()->create(['name' => 'Maria Souza', 'phone' => '11987654321']);
        $order = ServiceOrder::factory()->for(Vehicle::factory()->for($customer))->create(['customer_id' => $customer->id]);

        $this->getJson('/api/v1/search?q=souza')->assertJsonPath('data.customers.0.name', 'Maria Souza');
        $this->getJson('/api/v1/search?q=98765')->assertJsonPath('data.customers.0.id', $customer->id);
        $this->getJson('/api/v1/search?q=OS '.$order->number())->assertJsonPath('data.orders.0.id', $order->id);
    }

    public function test_requires_two_characters(): void
    {
        $this->getJson('/api/v1/search?q=a')->assertUnprocessable();
    }
}
