<?php

namespace Tests\Feature\ServiceOrders;

use App\Enums\ServiceOrderStatus;
use App\Models\Customer;
use App\Models\LaborService;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Vehicle $vehicle;

    private LaborService $shock;

    private LaborService $belt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost');
        $this->user = User::factory()->create(['name' => 'Mecânico']);
        $this->customer = Customer::factory()->create(['name' => 'Ruan Silva']);
        $this->vehicle = Vehicle::factory()->for($this->customer)->create(['plate' => 'RUA1N23', 'model' => 'ONIX LT', 'mileage' => 40000]);
        $this->shock = LaborService::factory()->create(['name' => 'Troca de amortecedor']);
        $this->belt = LaborService::factory()->create(['name' => 'Troca de correia dentada']);
        $this->actingAs($this->user);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'status' => 'in_progress',
            'mileage' => '45.300',
            'complaint' => 'Barulho na suspensão dianteira',
            'expected_at' => '2026-10-10',
            // Aberta já em andamento: exige orçamento completo (todos os itens com valor)
            'items' => [
                ['labor_service_id' => $this->shock->id, 'notes' => 'Dianteiros', 'price_cents' => 25000],
                ['labor_service_id' => $this->belt->id, 'price_cents' => 18000],
            ],
            ...$overrides,
        ];
    }

    private function createOrder(array $overrides = []): ServiceOrder
    {
        $id = $this->postJson('/api/v1/service-orders', $this->payload($overrides))->assertCreated()->json('data.id');

        return ServiceOrder::findOrFail($id);
    }

    // --- abertura -------------------------------------------------------------

    public function test_guest_cannot_access(): void
    {
        auth('web')->logout();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/service-orders')->assertUnauthorized();
    }

    public function test_open_order_for_customer_vehicle_with_services(): void
    {
        $response = $this->postJson('/api/v1/service-orders', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.status_label', 'Em andamento')
            ->assertJsonPath('data.mileage', 45300)
            ->assertJsonPath('data.customer.name', 'Ruan Silva')
            ->assertJsonPath('data.vehicle.plate', 'RUA1N23')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.name', 'Troca de amortecedor')
            ->assertJsonPath('data.items.0.notes', 'Dianteiros')
            ->assertJsonPath('data.events.0.type', 'created')
            ->assertJsonPath('data.events.0.user', 'Mecânico');

        $this->assertSame(str_pad((string) $response->json('data.id'), 5, '0', STR_PAD_LEFT), $response->json('data.number'));
        $this->assertNotNull(ServiceOrder::first()->started_at);
        // km de entrada maior atualiza o veículo
        $this->assertSame(45300, $this->vehicle->fresh()->mileage);
    }

    public function test_lower_mileage_does_not_reduce_vehicle_mileage(): void
    {
        $this->createOrder(['mileage' => '1000']);

        $this->assertSame(40000, $this->vehicle->fresh()->mileage);
    }

    public function test_vehicle_must_belong_to_customer(): void
    {
        $otherVehicle = Vehicle::factory()->create();

        $this->postJson('/api/v1/service-orders', $this->payload(['vehicle_id' => $otherVehicle->id]))
            ->assertJsonValidationErrors(['vehicle_id' => 'Este veículo não pertence ao cliente selecionado.']);
    }

    public function test_services_must_be_active_in_catalog(): void
    {
        $inactive = LaborService::factory()->inactive()->create();

        $this->postJson('/api/v1/service-orders', $this->payload(['items' => [['labor_service_id' => $inactive->id]]]))
            ->assertJsonValidationErrors(['items.0.labor_service_id']);
    }

    public function test_cannot_open_in_progress_without_complete_budget(): void
    {
        $this->postJson('/api/v1/service-orders', $this->payload(['items' => [['labor_service_id' => $this->shock->id]]]))
            ->assertJsonValidationErrors(['budget']);
    }

    public function test_initial_status_must_be_open_or_in_progress(): void
    {
        $this->postJson('/api/v1/service-orders', $this->payload(['status' => 'delivered']))
            ->assertJsonValidationErrors(['status']);
    }

    // --- acompanhamento -------------------------------------------------------

    public function test_status_flow_records_history_and_dates(): void
    {
        // Já aprovado na abertura (status in_progress): segue o fluxo de execução
        $order = $this->createOrder();
        $this->assertNotNull($order->started_at);

        $this->postJson("/api/v1/service-orders/{$order->id}/status", ['status' => 'waiting_parts', 'note' => 'Aguardando amortecedor do fornecedor'])
            ->assertOk()
            ->assertJsonPath('data.status_label', 'Aguardando peças')
            ->assertJsonPath('data.events.0.type', 'status_changed')
            ->assertJsonPath('data.events.0.from_status', 'in_progress')
            ->assertJsonPath('data.events.0.to_status', 'waiting_parts')
            ->assertJsonPath('data.events.0.description', "Status alterado: Em andamento → Aguardando peças.\nAguardando amortecedor do fornecedor");

        $this->postJson("/api/v1/service-orders/{$order->id}/status", ['status' => 'completed'])->assertOk();
        $this->postJson("/api/v1/service-orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('data.is_final', true);

        $order->refresh();
        $this->assertNotNull($order->completed_at);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame(4, $order->events()->count());
    }

    public function test_same_status_is_rejected(): void
    {
        $order = $this->createOrder();

        $this->postJson("/api/v1/service-orders/{$order->id}/status", ['status' => 'in_progress'])
            ->assertJsonValidationErrors(['status' => 'A OS já está com este status.']);
    }

    public function test_final_order_is_locked_until_reopened(): void
    {
        $order = $this->createOrder();
        $item = $order->items()->first();
        $this->postJson("/api/v1/service-orders/{$order->id}/status", ['status' => 'canceled'])->assertOk();

        $this->patchJson("/api/v1/service-orders/{$order->id}/items/{$item->id}", ['is_done' => true])
            ->assertJsonValidationErrors(['status' => 'A OS está Cancelada. Reabra a OS para alterar.']);
        $this->putJson("/api/v1/service-orders/{$order->id}", $this->payload())->assertUnprocessable();

        $this->postJson("/api/v1/service-orders/{$order->id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.events.0.description', 'OS reaberta: Cancelada → Em andamento.');
        $this->assertNull($order->fresh()->canceled_at);

        $this->patchJson("/api/v1/service-orders/{$order->id}/items/{$item->id}", ['is_done' => true])->assertOk();
    }

    public function test_checking_services_records_who_and_when(): void
    {
        $order = $this->createOrder();
        $item = $order->items()->first();

        $this->patchJson("/api/v1/service-orders/{$order->id}/items/{$item->id}", ['is_done' => true])
            ->assertOk()
            ->assertJsonPath('data.items.0.is_done', true)
            ->assertJsonPath('data.items.0.done_by', 'Mecânico')
            ->assertJsonPath('data.events.0.description', 'Serviço concluído: Troca de amortecedor.');

        $this->patchJson("/api/v1/service-orders/{$order->id}/items/{$item->id}", ['is_done' => false])
            ->assertJsonPath('data.items.0.is_done', false)
            ->assertJsonPath('data.items.0.done_by', null);
    }

    public function test_item_from_another_order_is_not_found(): void
    {
        $order = $this->createOrder();
        $otherItem = $this->createOrder()->items()->first();

        $this->patchJson("/api/v1/service-orders/{$order->id}/items/{$otherItem->id}", ['is_done' => true])->assertNotFound();
    }

    public function test_update_syncs_services_and_logs_changes(): void
    {
        $order = $this->createOrder();
        [$shockItem] = $order->items()->get();
        $brakes = LaborService::factory()->create(['name' => 'Troca de pastilhas de freio']);

        $this->putJson("/api/v1/service-orders/{$order->id}", [
            'mileage' => '45300',
            'complaint' => 'Barulho na suspensão dianteira e freio chiando',
            'expected_at' => '2026-10-10',
            'items' => [
                ['id' => $shockItem->id, 'notes' => 'Dianteiros e traseiros'],
                ['labor_service_id' => $brakes->id],
            ],
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.notes', 'Dianteiros e traseiros')
            ->assertJsonPath('data.items.1.name', 'Troca de pastilhas de freio');

        $descriptions = $order->events()->pluck('description')->all();
        $this->assertContains('Dados atualizados: relato do cliente.', $descriptions);
        $this->assertContains('Serviço adicionado: Troca de pastilhas de freio.', $descriptions);
        $this->assertContains('Serviço removido: Troca de correia dentada.', $descriptions);
    }

    public function test_service_name_is_kept_even_if_catalog_changes(): void
    {
        $order = $this->createOrder();
        $this->shock->update(['name' => 'Amortecedor (novo nome)']);
        $this->shock->delete();

        $this->getJson("/api/v1/service-orders/{$order->id}")->assertJsonPath('data.items.0.name', 'Troca de amortecedor');
    }

    public function test_notes_go_to_timeline(): void
    {
        $order = $this->createOrder();

        $this->postJson("/api/v1/service-orders/{$order->id}/notes", ['note' => 'Cliente autorizou por telefone'])
            ->assertOk()
            ->assertJsonPath('data.events.0.type', 'note')
            ->assertJsonPath('data.events.0.description', 'Cliente autorizou por telefone');

        $this->postJson("/api/v1/service-orders/{$order->id}/notes", ['note' => ''])->assertJsonValidationErrors(['note']);
    }

    // --- listagem e histórico -------------------------------------------------

    public function test_list_filters_and_progress(): void
    {
        $active = $this->createOrder();
        // is_done não é preenchível em massa: só o checklist (setItemDone) altera
        $item = $active->items()->first();
        $item->is_done = true;
        $item->save();
        ServiceOrder::factory()->status(ServiceOrderStatus::Delivered)->create();

        $this->getJson('/api/v1/service-orders')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', ServiceOrder::max('id'));

        $this->getJson('/api/v1/service-orders?status=active')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.items_count', 2)
            ->assertJsonPath('data.0.done_items_count', 1);

        $this->getJson('/api/v1/service-orders?status=delivered')->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/service-orders?vehicle_id={$this->vehicle->id}")->assertJsonPath('meta.total', 1);
    }

    public function test_search_by_number_customer_and_plate(): void
    {
        $order = $this->createOrder();
        ServiceOrder::factory()->for(Vehicle::factory()->state(['model' => 'CIVIC EXL', 'plate' => 'CIV1C00']))->create();

        foreach ([str_pad((string) $order->id, 5, '0', STR_PAD_LEFT), 'ruan', 'rua-1n', 'onix'] as $search) {
            $this->getJson('/api/v1/service-orders?search='.urlencode($search))
                ->assertJsonPath('meta.total', 1, "Busca por '{$search}'")
                ->assertJsonPath('data.0.id', $order->id);
        }
    }

    public function test_vehicle_history_lists_orders_with_services(): void
    {
        $first = $this->createOrder();
        $first->items()->update(['is_done' => true]);
        $this->postJson("/api/v1/service-orders/{$first->id}/status", ['status' => 'delivered']);
        $second = $this->createOrder(['status' => 'open', 'items' => [['labor_service_id' => $this->belt->id]]]);

        $this->getJson("/api/v1/vehicles/{$this->vehicle->id}/history")
            ->assertOk()
            ->assertJsonPath('data.vehicle.plate', 'RUA1N23')
            ->assertJsonPath('data.customer.name', 'Ruan Silva')
            ->assertJsonCount(2, 'data.orders')
            ->assertJsonPath('data.orders.0.id', $second->id)
            ->assertJsonPath('data.orders.1.status', 'delivered')
            ->assertJsonPath('data.orders.1.items.0.is_done', true);
    }

    public function test_history_survives_customer_deletion(): void
    {
        $order = $this->createOrder();
        $this->actingAs(User::factory()->master()->create())->deleteJson("/api/v1/customers/{$this->customer->id}")->assertNoContent();

        $this->getJson("/api/v1/vehicles/{$this->vehicle->id}/history")
            ->assertOk()
            ->assertJsonPath('data.customer.deleted', true)
            ->assertJsonCount(1, 'data.orders');
        $this->getJson("/api/v1/service-orders/{$order->id}")->assertJsonPath('data.vehicle.deleted', true);
    }
}
