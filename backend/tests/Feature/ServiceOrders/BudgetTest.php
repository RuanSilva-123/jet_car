<?php

namespace Tests\Feature\ServiceOrders;

use App\Models\Customer;
use App\Models\LaborService;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orçamento: valores, peças, desconto, envio, aprovação/recusa, trava de execução e PDFs.
 */
class BudgetTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Vehicle $vehicle;

    private LaborService $shock;

    private LaborService $belt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost');
        $this->actingAs(User::factory()->create(['name' => 'Atendente']));
        $this->customer = Customer::factory()->create(['name' => 'Ruan Silva']);
        $this->vehicle = Vehicle::factory()->for($this->customer)->create(['plate' => 'RUA1N23']);
        $this->shock = LaborService::factory()->create(['name' => 'Troca de amortecedor']);
        $this->belt = LaborService::factory()->create(['name' => 'Troca de correia dentada']);
    }

    /**
     * Orçamento do exemplo: 2 serviços (R$ 250 + R$ 180) e 2 peças (2 × R$ 320,50 + 4,5 × R$ 42,90), desconto de R$ 30.
     *
     * @return array<string, mixed>
     */
    private function budget(array $overrides = []): array
    {
        return [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'status' => 'open',
            'items' => [
                ['labor_service_id' => $this->shock->id, 'notes' => 'Dianteiros', 'price_cents' => 25000],
                ['labor_service_id' => $this->belt->id, 'price_cents' => 18000],
            ],
            'parts' => [
                ['name' => 'Amortecedor dianteiro', 'part_number' => 'AM-4021', 'quantity' => 2, 'unit_price_cents' => 32050],
                ['name' => 'Óleo 5W30 (litro)', 'quantity' => 4.5, 'unit_price_cents' => 4290],
            ],
            'discount_cents' => 3000,
            ...$overrides,
        ];
    }

    private function openBudget(array $overrides = []): ServiceOrder
    {
        $id = $this->postJson('/api/v1/service-orders', $this->budget($overrides))->assertCreated()->json('data.id');

        return ServiceOrder::findOrFail($id);
    }

    public function test_budget_totals_are_calculated_in_cents(): void
    {
        $this->postJson('/api/v1/service-orders', $this->budget())
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.status_label', 'Aberta')
            ->assertJsonPath('data.labor_total_cents', 43000)
            // 2 × 320,50 = 641,00  +  4,5 × 42,90 = 193,05
            ->assertJsonPath('data.parts_total_cents', 83405)
            ->assertJsonPath('data.discount_cents', 3000)
            ->assertJsonPath('data.total_cents', 123405)
            ->assertJsonPath('data.parts.1.quantity', 4.5)
            ->assertJsonPath('data.parts.1.total_cents', 19305)
            ->assertJsonPath('data.items.0.price_cents', 25000)
            ->assertJsonPath('data.budget_approved_at', null);
    }

    public function test_discount_cannot_exceed_budget(): void
    {
        $this->postJson('/api/v1/service-orders', $this->budget(['discount_cents' => 999999]))
            ->assertJsonValidationErrors(['discount_cents']);

        $this->assertSame(0, ServiceOrder::count());
    }

    public function test_part_validation(): void
    {
        $this->postJson('/api/v1/service-orders', $this->budget(['parts' => [['name' => '', 'quantity' => 0, 'unit_price_cents' => -1]]]))
            ->assertJsonValidationErrors([
                'parts.0.name' => 'Informe o nome da peça.',
                'parts.0.quantity' => 'Quantidade deve ser maior que zero.',
                'parts.0.unit_price_cents' => 'Valor da peça inválido.',
            ]);
    }

    public function test_service_cannot_start_before_customer_approval(): void
    {
        $order = $this->openBudget();

        foreach (['in_progress', 'waiting_parts', 'completed', 'delivered'] as $status) {
            $this->postJson("/api/v1/service-orders/{$order->id}/status", ['status' => $status])
                ->assertJsonValidationErrors(['status' => 'Registre a aprovação do orçamento pelo cliente antes de iniciar o serviço.']);
        }

        $item = $order->items()->first();
        $this->patchJson("/api/v1/service-orders/{$order->id}/items/{$item->id}", ['is_done' => true])
            ->assertJsonValidationErrors(['status']);
    }

    public function test_send_then_approve_starts_the_service(): void
    {
        $order = $this->openBudget();

        $this->postJson("/api/v1/service-orders/{$order->id}/budget/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting_approval')
            ->assertJsonPath('data.events.0.type', 'budget_sent')
            ->assertJsonPath('data.events.0.description', 'Orçamento enviado ao cliente: R$ 1.234,05.');

        $this->postJson("/api/v1/service-orders/{$order->id}/budget/approve", ['note' => 'Aprovado por WhatsApp'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.budget_approved_total_cents', 123405)
            ->assertJsonPath('data.budget_changed_after_approval', false)
            ->assertJsonPath('data.events.0.type', 'budget_approved')
            ->assertJsonPath('data.events.0.description', "Orçamento aprovado pelo cliente: R$ 1.234,05. Serviço iniciado.\nAprovado por WhatsApp");

        $this->assertNotNull($order->fresh()->started_at);
        $this->assertNotNull($order->fresh()->budget_sent_at);
    }

    public function test_cannot_send_or_approve_empty_or_incomplete_budget(): void
    {
        $empty = $this->openBudget(['items' => [], 'parts' => [], 'discount_cents' => 0]);
        $this->postJson("/api/v1/service-orders/{$empty->id}/budget/send")
            ->assertJsonValidationErrors(['budget' => 'Adicione os serviços e peças antes de enviar ou aprovar o orçamento.']);

        // Peça ainda "a definir"
        $incomplete = $this->openBudget(['parts' => [['name' => 'Correia dentada', 'quantity' => 1]], 'discount_cents' => 0]);
        $this->postJson("/api/v1/service-orders/{$incomplete->id}/budget/send")
            ->assertJsonValidationErrors(['budget' => 'Há 1 item sem valor. Monte o orçamento antes de enviar ou aprovar.']);
        $this->postJson("/api/v1/service-orders/{$incomplete->id}/budget/approve")->assertJsonValidationErrors(['budget']);

        // Serviço sem custo (zero) é um valor definido
        $free = $this->openBudget(['items' => [['labor_service_id' => $this->shock->id, 'price_cents' => 0]], 'parts' => [], 'discount_cents' => 0]);
        $this->postJson("/api/v1/service-orders/{$free->id}/budget/send")->assertOk();
    }

    // --- fluxo: entrada → diagnóstico → orçamento ------------------------------------

    public function test_entry_only_then_diagnosis_then_pricing(): void
    {
        // 1. Entrada: só o veículo e o relato
        $id = $this->postJson('/api/v1/service-orders', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'mileage' => '120.500',
            'complaint' => 'Barulho na suspensão',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.status_label', 'Aberta')
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.unpriced_count', 0)
            ->json('data.id');

        // 2. Diagnóstico: serviços e peças sem valores
        $this->postJson("/api/v1/service-orders/{$id}/items", ['labor_service_id' => $this->shock->id, 'notes' => 'Dianteiros'])
            ->assertOk()
            ->assertJsonPath('data.items.0.name', 'Troca de amortecedor')
            ->assertJsonPath('data.items.0.price_cents', null)
            ->assertJsonPath('data.events.0.type', 'item_added');

        $response = $this->postJson("/api/v1/service-orders/{$id}/parts", ['name' => ' Amortecedor dianteiro ', 'part_number' => '', 'quantity' => 2])
            ->assertOk()
            ->assertJsonPath('data.parts.0.name', 'Amortecedor dianteiro')
            ->assertJsonPath('data.parts.0.part_number', null)
            ->assertJsonPath('data.parts.0.unit_price_cents', null)
            ->assertJsonPath('data.parts.0.total_cents', null)
            ->assertJsonPath('data.unpriced_count', 2)
            ->assertJsonPath('data.total_cents', 0)
            ->assertJsonPath('data.events.0.description', 'Peça adicionada: 2× Amortecedor dianteiro.');

        // Sem valores não dá para enviar
        $this->postJson("/api/v1/service-orders/{$id}/budget/send")->assertJsonValidationErrors(['budget']);

        // 3. Orçamento: só os valores (dados da entrada continuam)
        $this->putJson("/api/v1/service-orders/{$id}", [
            'items' => [['id' => $response->json('data.items.0.id'), 'price_cents' => 25000]],
            'parts' => [['id' => $response->json('data.parts.0.id'), 'name' => 'Amortecedor dianteiro', 'quantity' => 2, 'unit_price_cents' => 32050]],
            'discount_cents' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('data.unpriced_count', 0)
            ->assertJsonPath('data.total_cents', 25000 + 64100)
            ->assertJsonPath('data.items.0.notes', 'Dianteiros')
            ->assertJsonPath('data.complaint', 'Barulho na suspensão')
            ->assertJsonPath('data.mileage', 120500);

        $this->postJson("/api/v1/service-orders/{$id}/budget/send")->assertOk()->assertJsonPath('data.status', 'waiting_approval');
    }

    public function test_editing_entry_keeps_services_parts_and_prices(): void
    {
        $order = $this->openBudget();

        $this->putJson("/api/v1/service-orders/{$order->id}", ['complaint' => 'Barulho e vibração', 'mileage' => '50000', 'expected_at' => null, 'notes' => null])
            ->assertOk()
            ->assertJsonPath('data.complaint', 'Barulho e vibração')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonCount(2, 'data.parts')
            ->assertJsonPath('data.total_cents', 123405);
    }

    public function test_removing_lines(): void
    {
        $order = $this->openBudget();
        [$item] = $order->items()->get();
        [$part] = $order->parts()->get();

        $this->deleteJson("/api/v1/service-orders/{$order->id}/parts/{$part->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.parts')
            ->assertJsonPath('data.total_cents', 123405 - 64100)
            ->assertJsonPath('data.events.0.description', 'Peça removida: 2× Amortecedor dianteiro.');

        $this->deleteJson("/api/v1/service-orders/{$order->id}/items/{$item->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.events.0.type', 'item_removed');

        // Linha de outra OS
        $other = $this->openBudget();
        $this->deleteJson("/api/v1/service-orders/{$order->id}/parts/{$other->parts()->first()->id}")->assertNotFound();
    }

    public function test_done_service_cannot_be_removed(): void
    {
        $order = $this->openBudget();
        $this->postJson("/api/v1/service-orders/{$order->id}/budget/approve");
        $item = $order->items()->first();
        $this->patchJson("/api/v1/service-orders/{$order->id}/items/{$item->id}", ['is_done' => true])->assertOk();

        $this->deleteJson("/api/v1/service-orders/{$order->id}/items/{$item->id}")->assertJsonValidationErrors(['item']);
    }

    public function test_new_unpriced_line_after_approval_flags_budget(): void
    {
        $order = $this->openBudget();
        $this->postJson("/api/v1/service-orders/{$order->id}/budget/approve")->assertJsonPath('data.budget_changed_after_approval', false);

        // Descobriu outra peça durante o serviço
        $this->postJson("/api/v1/service-orders/{$order->id}/parts", ['name' => 'Bieleta', 'quantity' => 2])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.budget_changed_after_approval', true);
    }

    public function test_rejection_can_send_back_to_revision_or_cancel(): void
    {
        $revise = $this->openBudget();
        $this->postJson("/api/v1/service-orders/{$revise->id}/budget/send");

        $this->postJson("/api/v1/service-orders/{$revise->id}/budget/reject", ['cancel' => false, 'note' => 'Achou caro'])
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.events.0.description', "Orçamento recusado pelo cliente (R$ 1.234,05). Orçamento em revisão.\nAchou caro");

        $cancel = $this->openBudget();
        $this->postJson("/api/v1/service-orders/{$cancel->id}/budget/reject", ['cancel' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled')
            ->assertJsonPath('data.is_final', true);
    }

    public function test_changing_approved_budget_is_flagged_and_logged(): void
    {
        $order = $this->openBudget();
        $this->postJson("/api/v1/service-orders/{$order->id}/budget/approve")->assertOk();
        $items = $order->items()->get();

        $this->putJson("/api/v1/service-orders/{$order->id}", [
            'items' => [
                ['id' => $items[0]->id, 'notes' => 'Dianteiros', 'price_cents' => 30000],
                ['id' => $items[1]->id, 'price_cents' => 18000],
            ],
            'discount_cents' => 3000,
        ])
            ->assertOk()
            ->assertJsonPath('data.total_cents', 128405)
            ->assertJsonPath('data.budget_approved_total_cents', 123405)
            ->assertJsonPath('data.budget_changed_after_approval', true)
            // Sem 'parts' na requisição, as peças continuam lá
            ->assertJsonCount(2, 'data.parts')
            ->assertJsonPath('data.events.0.type', 'budget_updated');

        $this->assertStringContainsString('valor aprovado pelo cliente foi R$ 1.234,05', $order->events()->first()->description);

        // Nova aprovação com o valor atualizado
        $this->postJson("/api/v1/service-orders/{$order->id}/budget/approve")
            ->assertJsonPath('data.budget_approved_total_cents', 128405)
            ->assertJsonPath('data.budget_changed_after_approval', false)
            ->assertJsonPath('data.status', 'in_progress');
    }

    public function test_update_syncs_parts(): void
    {
        $order = $this->openBudget();
        [$shockPart] = $order->parts()->get();

        $this->putJson("/api/v1/service-orders/{$order->id}", [
            'items' => $order->items()->get()->map(fn ($item) => ['id' => $item->id, 'price_cents' => $item->price_cents])->all(),
            'parts' => [
                ['id' => $shockPart->id, 'name' => 'Amortecedor dianteiro', 'quantity' => 1, 'unit_price_cents' => 32050],
                ['name' => 'Kit batente', 'quantity' => 2, 'unit_price_cents' => 5990],
            ],
            'discount_cents' => 0,
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data.parts')
            ->assertJsonPath('data.parts.1.name', 'Kit batente')
            ->assertJsonPath('data.parts_total_cents', 32050 + 11980);
    }

    public function test_opening_already_approved_records_approval(): void
    {
        $this->postJson('/api/v1/service-orders', $this->budget(['status' => 'in_progress']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.budget_approved_total_cents', 123405)
            ->assertJsonPath('data.events.0.description', 'OS aberta com orçamento aprovado pelo cliente (R$ 1.234,05) e serviço iniciado.');
    }

    public function test_list_shows_totals(): void
    {
        $this->openBudget();

        $this->getJson('/api/v1/service-orders')->assertJsonPath('data.0.total_cents', 123405);
    }

    // --- PDFs -------------------------------------------------------------------

    public function test_budget_and_report_pdfs(): void
    {
        $order = $this->openBudget();

        $budget = $this->get("/api/v1/service-orders/{$order->id}/pdf/budget")->assertOk();
        $this->assertSame('application/pdf', $budget->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $budget->getContent());
        $this->assertStringContainsString('inline; filename=Orcamento-'.$order->number().'.pdf', $budget->headers->get('Content-Disposition'));

        $report = $this->get("/api/v1/service-orders/{$order->id}/pdf/report?download=1")->assertOk();
        $this->assertStringStartsWith('%PDF', $report->getContent());
        $this->assertStringContainsString('attachment; filename=Ordem-de-servico-'.$order->number().'.pdf', $report->headers->get('Content-Disposition'));

        $this->get("/api/v1/service-orders/{$order->id}/pdf/outro")->assertNotFound();
    }

    public function test_pdf_requires_login(): void
    {
        $order = $this->openBudget();
        auth('web')->logout();
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/service-orders/{$order->id}/pdf/budget")->assertUnauthorized();
    }
}
