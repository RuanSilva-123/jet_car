<?php

namespace Tests\Feature\Warranty;

use App\Enums\ServiceOrderStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\LaborService;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\BudgetLink;
use App\Support\ShopSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retorno em garantia: vínculo com a OS original, serviços refeitos sem custo e relatório.
 */
class WarrantyReturnTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    private LaborService $brake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost');
        $this->actingAs(User::factory()->create());
        $this->vehicle = Vehicle::factory()->for(Customer::factory())->create();
        $this->brake = LaborService::factory()->create(['name' => 'Troca de pastilhas']);
        ShopSettings::save(['warranty_days' => 90]);
    }

    private function delivered(int $daysAgo, ?User $mechanic = null): ServiceOrder
    {
        $order = ServiceOrder::factory()->for($this->vehicle)->status(ServiceOrderStatus::Delivered)->create(['customer_id' => $this->vehicle->customer_id]);
        $order->forceFill(['delivered_at' => now()->subDays($daysAgo), 'total_cents' => 20000, 'labor_total_cents' => 20000])->save();
        $item = $order->items()->create(['labor_service_id' => $this->brake->id, 'name' => 'Troca de pastilhas', 'price_cents' => 20000, 'position' => 0]);
        $item->forceFill(['is_done' => true, 'done_at' => $order->delivered_at, 'mechanic_id' => $mechanic?->id])->save();

        return $order;
    }

    private function newOrder(): ServiceOrder
    {
        return ServiceOrder::factory()->for($this->vehicle)->create(['customer_id' => $this->vehicle->customer_id]);
    }

    public function test_candidates_are_delivered_orders_within_warranty(): void
    {
        $recent = $this->delivered(30);
        $this->delivered(120); // fora da garantia
        ServiceOrder::factory()->status(ServiceOrderStatus::Delivered)->create(); // outro veículo
        $order = $this->newOrder();

        $this->getJson("/api/v1/service-orders/{$order->id}/warranty/candidates")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id)
            ->assertJsonPath('data.0.items.0.name', 'Troca de pastilhas')
            ->assertJsonPath('warranty_days', 90);
    }

    public function test_links_return_and_redoes_services_free(): void
    {
        $original = $this->delivered(30);
        $order = $this->newOrder();
        $itemId = $original->items()->value('id');

        $this->postJson("/api/v1/service-orders/{$order->id}/warranty", ['warranty_of_id' => $original->id, 'item_ids' => [$itemId]])
            ->assertOk()
            ->assertJsonPath('data.warranty_of.id', $original->id)
            ->assertJsonPath('data.items.0.price_cents', 0)
            ->assertJsonPath('data.items.0.warranty_of_item_id', $itemId)
            ->assertJsonPath('data.total_cents', 0)
            ->assertJsonPath('data.events.0.type', 'warranty_linked');

        $this->getJson("/api/v1/service-orders/{$original->id}")
            ->assertJsonPath('data.warranty_returns.0.id', $order->id)
            ->assertJsonPath('data.events.0.type', 'warranty_return');

        $this->getJson('/api/v1/service-orders')->assertJsonPath('data.0.warranty_of_id', $original->id);
        $this->get("/api/v1/service-orders/{$order->id}/pdf/report")->assertOk();

        // No link do orçamento o cliente vê "Garantia" no lugar do valor
        $this->getJson('/api/v1/public/budgets/'.BudgetLink::make($order->fresh())['token'])
            ->assertOk()
            ->assertJsonPath('data.order.items.0.warranty', true);
    }

    public function test_delivered_order_counts_even_without_ticked_services(): void
    {
        // Entregue sem marcar os itens como feitos: o serviço foi feito do mesmo jeito
        $original = $this->delivered(10);
        $original->items()->update(['is_done' => false, 'done_at' => null]);
        ServiceOrder::factory()->for($this->vehicle)->status(ServiceOrderStatus::Delivered)->create(['customer_id' => $this->vehicle->customer_id]); // sem serviços
        $order = $this->newOrder();

        $this->getJson("/api/v1/service-orders/{$order->id}/warranty/candidates")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $original->id);

        $this->postJson("/api/v1/service-orders/{$order->id}/warranty", ['warranty_of_id' => $original->id, 'item_ids' => [$original->items()->value('id')]])
            ->assertOk()
            ->assertJsonPath('data.warranty_of.id', $original->id);
    }

    public function test_out_of_warranty_is_refused(): void
    {
        $old = $this->delivered(120);
        $order = $this->newOrder();

        $this->postJson("/api/v1/service-orders/{$order->id}/warranty", ['warranty_of_id' => $old->id, 'item_ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('warranty_of_id');
    }

    public function test_items_must_come_from_original_order(): void
    {
        $original = $this->delivered(10);
        $other = $this->delivered(20);
        $order = $this->newOrder();

        $this->postJson("/api/v1/service-orders/{$order->id}/warranty", ['warranty_of_id' => $original->id, 'item_ids' => [$other->items()->value('id')]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('item_ids');
    }

    public function test_unlink_removes_pending_warranty_items(): void
    {
        $original = $this->delivered(10);
        $order = $this->newOrder();
        $this->postJson("/api/v1/service-orders/{$order->id}/warranty", ['warranty_of_id' => $original->id, 'item_ids' => [$original->items()->value('id')]])->assertOk();

        $this->deleteJson("/api/v1/service-orders/{$order->id}/warranty")
            ->assertOk()
            ->assertJsonPath('data.warranty_of', null)
            ->assertJsonCount(0, 'data.items');
    }

    public function test_warranty_report_shows_which_services_return(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Master]));
        $mechanic = User::factory()->create(['name' => 'Bruno', 'role' => UserRole::Mechanic]);
        $original = $this->delivered(10, $mechanic);
        $this->delivered(5);
        $order = $this->newOrder();
        $this->postJson("/api/v1/service-orders/{$order->id}/warranty", ['warranty_of_id' => $original->id, 'item_ids' => [$original->items()->value('id')]])->assertOk();

        $from = now()->subDays(30)->toDateString();
        $to = now()->toDateString();
        $this->getJson("/api/v1/reports/warranty?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('data.rows.0.name', 'Troca de pastilhas')
            ->assertJsonPath('data.rows.0.returns', 1)
            ->assertJsonPath('data.rows.0.executions', 2)
            ->assertJsonPath('data.rows.0.rate', 50)
            ->assertJsonPath('data.rows.0.mechanics', 'Bruno')
            ->assertJsonPath('data.summary.returns', 1);

        // Serviço de garantia (R$ 0) não entra nos mais vendidos
        $this->getJson("/api/v1/reports/services?from={$from}&to={$to}")->assertJsonPath('data.rows.0.count', 2);
    }
}
