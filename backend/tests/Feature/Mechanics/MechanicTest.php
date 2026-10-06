<?php

namespace Tests\Feature\Mechanics;

use App\Enums\UserRole;
use App\Models\LaborService;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Perfil mecânico: atribuição de serviços e restrição ao financeiro.
 */
class MechanicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost');
    }

    private function orderWithItem(): array
    {
        $order = ServiceOrder::factory()->create();
        $item = $order->items()->create(['labor_service_id' => LaborService::factory()->create()->id, 'name' => 'Troca de óleo', 'position' => 0]);

        return [$order, $item];
    }

    public function test_master_can_create_mechanic_account(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Master]));

        $this->postJson('/api/v1/users', [
            'name' => 'Carlos Mecânico',
            'email' => 'carlos@jetcar.com.br',
            'role' => 'mechanic',
            'is_active' => true,
            'password' => 'senha1234',
            'password_confirmation' => 'senha1234',
        ])->assertCreated()
            ->assertJsonPath('data.role_label', 'Mecânico')
            ->assertJsonPath('data.can_manage_finance', false);
    }

    public function test_lists_only_active_mechanics(): void
    {
        $this->actingAs(User::factory()->create());
        User::factory()->create(['name' => 'Bruno', 'role' => UserRole::Mechanic]);
        User::factory()->create(['name' => 'Inativo', 'role' => UserRole::Mechanic, 'is_active' => false]);

        $this->getJson('/api/v1/mechanics')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Bruno');
    }

    public function test_service_can_be_assigned_to_a_mechanic_and_filtered(): void
    {
        $this->actingAs(User::factory()->create());
        $mechanic = User::factory()->create(['name' => 'Bruno', 'role' => UserRole::Mechanic]);
        [$order, $item] = $this->orderWithItem();
        ServiceOrder::factory()->create();

        $this->putJson("/api/v1/service-orders/{$order->id}/items/{$item->id}/mechanic", ['mechanic_id' => $mechanic->id])
            ->assertOk()
            ->assertJsonPath('data.items.0.mechanic', 'Bruno')
            ->assertJsonPath('data.events.0.type', 'item_assigned');

        $this->getJson("/api/v1/service-orders?mechanic_id={$mechanic->id}")->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($mechanic);
        $this->getJson('/api/v1/service-orders?mechanic_id=me')->assertOk()->assertJsonPath('data.0.id', $order->id);

        $this->putJson("/api/v1/service-orders/{$order->id}/items/{$item->id}/mechanic", ['mechanic_id' => null])
            ->assertOk()
            ->assertJsonPath('data.items.0.mechanic', null);
    }

    public function test_only_mechanics_can_be_assigned(): void
    {
        $this->actingAs($admin = User::factory()->create());
        [$order, $item] = $this->orderWithItem();

        $this->putJson("/api/v1/service-orders/{$order->id}/items/{$item->id}/mechanic", ['mechanic_id' => $admin->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mechanic_id');
    }

    public function test_mechanic_cannot_access_finance(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Mechanic]));
        [$order] = $this->orderWithItem();

        $this->getJson('/api/v1/receivables')->assertForbidden();
        $this->getJson('/api/v1/reports/revenue')->assertForbidden();
        $this->postJson("/api/v1/service-orders/{$order->id}/payments", ['method' => 'pix', 'amount_cents' => 100])->assertForbidden();
        // Mas usa a OS normalmente
        $this->getJson("/api/v1/service-orders/{$order->id}")->assertOk();
    }
}
