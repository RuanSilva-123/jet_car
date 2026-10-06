<?php

namespace Tests\Feature\Inventory;

use App\Enums\ServiceOrderStatus;
use App\Enums\UserRole;
use App\Models\Part;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Estoque de peças: catálogo, entradas/ajustes e baixa automática na OS.
 */
class PartInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost');
        $this->actingAs(User::factory()->create(['role' => UserRole::Master]));
    }

    private function stockOf(Part $part): float
    {
        return (float) $part->fresh()->stock_quantity;
    }

    public function test_part_is_created_with_initial_stock(): void
    {
        $this->postJson('/api/v1/parts', [
            'name' => 'Filtro de óleo',
            'part_number' => 'fo-123',
            'unit' => 'un',
            'cost_cents' => 2000,
            'price_cents' => 3500,
            'min_stock' => 2,
            'is_active' => true,
            'initial_stock' => 5,
        ])->assertCreated()
            ->assertJsonPath('data.part_number', 'FO-123')
            ->assertJsonPath('data.stock_quantity', 5)
            ->assertJsonPath('data.margin_percent', 42.9)
            ->assertJsonPath('data.is_low_stock', false);

        $part = Part::firstOrFail();
        $this->getJson("/api/v1/parts/{$part->id}/movements")->assertJsonPath('data.0.type', 'entry')->assertJsonPath('data.0.balance_after', 5);
    }

    public function test_stock_entry_and_inventory_adjustment(): void
    {
        $part = Part::factory()->stock(3)->create(['cost_cents' => 1000]);

        $this->postJson("/api/v1/parts/{$part->id}/stock", ['type' => 'entry', 'quantity' => 10, 'unit_cost_cents' => 1200])
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 13)
            ->assertJsonPath('data.cost_cents', 1200);

        $this->postJson("/api/v1/parts/{$part->id}/stock", ['type' => 'adjustment', 'quantity' => 11, 'notes' => 'Contagem mensal'])
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 11);

        $this->getJson("/api/v1/parts/{$part->id}/movements")->assertJsonPath('data.0.quantity', -2);
    }

    public function test_low_stock_filter(): void
    {
        Part::factory()->stock(1, 2)->create(['name' => 'Pastilha']);
        Part::factory()->stock(10, 2)->create(['name' => 'Vela']);
        Part::factory()->stock(-1)->create(['name' => 'Correia']);

        $this->getJson('/api/v1/parts?status=low')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.low_stock', 2);
    }

    public function test_part_added_to_order_leaves_stock_and_returns_when_removed(): void
    {
        $part = Part::factory()->stock(10)->create(['name' => 'Óleo 5W30', 'price_cents' => 4500]);
        $order = ServiceOrder::factory()->create();

        $response = $this->postJson("/api/v1/service-orders/{$order->id}/parts", ['part_id' => $part->id, 'quantity' => 4.5])
            ->assertOk()
            ->assertJsonPath('data.parts.0.name', 'Óleo 5W30')
            ->assertJsonPath('data.parts.0.part_id', $part->id)
            ->assertJsonPath('data.parts.0.unit_price_cents', 4500);
        $this->assertSame(5.5, $this->stockOf($part));

        $lineId = $response->json('data.parts.0.id');
        $this->deleteJson("/api/v1/service-orders/{$order->id}/parts/{$lineId}")->assertOk();
        $this->assertSame(10.0, $this->stockOf($part));
    }

    public function test_budget_update_moves_only_the_quantity_difference(): void
    {
        $part = Part::factory()->stock(10)->create();
        $order = ServiceOrder::factory()->create();
        $lineId = $this->postJson("/api/v1/service-orders/{$order->id}/parts", ['part_id' => $part->id, 'quantity' => 2])->json('data.parts.0.id');

        $this->putJson("/api/v1/service-orders/{$order->id}", [
            'parts' => [['id' => $lineId, 'name' => $part->name, 'quantity' => 3, 'unit_price_cents' => 1000]],
        ])->assertOk();
        $this->assertSame(7.0, $this->stockOf($part));

        $other = Part::factory()->stock(5)->create();
        $this->putJson("/api/v1/service-orders/{$order->id}", [
            'parts' => [['part_id' => $other->id, 'name' => $other->name, 'quantity' => 1]],
        ])->assertOk();
        $this->assertSame(10.0, $this->stockOf($part));
        $this->assertSame(4.0, $this->stockOf($other));
    }

    public function test_canceling_order_returns_parts_and_reopening_takes_them_again(): void
    {
        $part = Part::factory()->stock(10)->create();
        $order = ServiceOrder::factory()->create();
        $this->postJson("/api/v1/service-orders/{$order->id}/parts", ['part_id' => $part->id, 'quantity' => 2])->assertOk();

        $this->postJson("/api/v1/service-orders/{$order->id}/status", ['status' => 'canceled'])->assertOk();
        $this->assertSame(10.0, $this->stockOf($part));

        $this->postJson("/api/v1/service-orders/{$order->id}/status", ['status' => 'open'])->assertOk();
        $this->assertSame(8.0, $this->stockOf($part));
        $this->assertSame(ServiceOrderStatus::Open, $order->fresh()->status);
    }

    public function test_free_text_part_does_not_touch_stock(): void
    {
        $order = ServiceOrder::factory()->create();

        $this->postJson("/api/v1/service-orders/{$order->id}/parts", ['name' => 'Peça avulsa', 'quantity' => 1])
            ->assertOk()
            ->assertJsonPath('data.parts.0.part_id', null);
    }

    public function test_only_master_deletes_parts(): void
    {
        $part = Part::factory()->create();

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->deleteJson("/api/v1/parts/{$part->id}")->assertForbidden();
    }
}
