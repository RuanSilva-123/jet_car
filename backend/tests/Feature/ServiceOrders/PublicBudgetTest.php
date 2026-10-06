<?php

namespace Tests\Feature\ServiceOrders;

use App\Enums\ServiceOrderStatus;
use App\Models\LaborService;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Support\BudgetLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orçamento por link assinado: o cliente vê e aprova/recusa sem login.
 */
class PublicBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function readyOrder(): ServiceOrder
    {
        $this->actingAs(User::factory()->create());
        $order = ServiceOrder::factory()->create();
        $this->postJson("/api/v1/service-orders/{$order->id}/items", ['labor_service_id' => LaborService::factory()->create(['name' => 'Alinhamento'])->id])->assertOk();
        $item = $order->items()->first();
        $this->putJson("/api/v1/service-orders/{$order->id}", ['items' => [['id' => $item->id, 'price_cents' => 12000]]])->assertOk();
        $this->postJson("/api/v1/service-orders/{$order->id}/budget/send")->assertOk();

        // O cliente não está logado
        $this->app['auth']->forgetGuards();

        return $order->fresh();
    }

    private function path(ServiceOrder $order, string $suffix = ''): string
    {
        return '/api/v1/public/budgets/'.BudgetLink::make($order)['token'].$suffix;
    }

    public function test_detail_exposes_public_link_once_budget_is_ready(): void
    {
        $order = $this->readyOrder();
        $this->actingAs(User::factory()->create());

        $url = $this->getJson("/api/v1/service-orders/{$order->id}")->assertOk()->json('data.public_budget_url');
        $this->assertStringContainsString('/orcamento/'.$order->id.'.', $url);
    }

    public function test_customer_sees_budget_without_login(): void
    {
        $order = $this->readyOrder();

        $this->getJson($this->path($order))
            ->assertOk()
            ->assertJsonPath('data.state', 'awaiting')
            ->assertJsonPath('data.order.items.0.name', 'Alinhamento')
            ->assertJsonPath('data.order.total_cents', 12000)
            ->assertJsonMissingPath('data.order.customer.phone');
    }

    public function test_customer_approves_through_link(): void
    {
        $order = $this->readyOrder();

        $this->postJson($this->path($order, '/approve'), ['total_cents' => 12000, 'name' => 'Ruan'])
            ->assertOk()
            ->assertJsonPath('data.state', 'approved');

        $order->refresh();
        $this->assertSame(ServiceOrderStatus::InProgress, $order->status);
        $this->assertSame(12000, $order->budget_approved_total_cents);
        $event = $order->events()->first();
        $this->assertNull($event->user_id);
        $this->assertStringContainsString('pelo link', $event->description);

        // Não aprova duas vezes
        $this->postJson($this->path($order, '/approve'), ['total_cents' => 12000])->assertUnprocessable();
    }

    public function test_customer_rejects_and_must_wait_for_new_budget(): void
    {
        $order = $this->readyOrder();

        $this->postJson($this->path($order, '/reject'), ['total_cents' => 12000, 'reason' => 'Muito caro'])
            ->assertOk()
            ->assertJsonPath('data.state', 'rejected');
        $this->assertSame(ServiceOrderStatus::Open, $order->fresh()->status);

        $this->postJson($this->path($order, '/approve'), ['total_cents' => 12000])->assertUnprocessable();
    }

    public function test_approval_requires_the_total_the_customer_saw(): void
    {
        $order = $this->readyOrder();

        $this->postJson($this->path($order, '/approve'), ['total_cents' => 9999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('budget');
    }

    public function test_tampered_and_expired_links_are_refused(): void
    {
        $order = $this->readyOrder();
        [, $expires, $signature] = explode('.', BudgetLink::make($order)['token']);

        $other = ServiceOrder::factory()->create();
        $this->getJson("/api/v1/public/budgets/{$other->id}.{$expires}.{$signature}")->assertNotFound();

        $this->travel(60)->days();
        $this->getJson("/api/v1/public/budgets/{$order->id}.{$expires}.{$signature}")->assertStatus(410);
    }
}
