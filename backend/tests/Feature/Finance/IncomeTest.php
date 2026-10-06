<?php

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\Income;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Outras entradas do caixa (não vêm de OS) e o efeito delas no fluxo de caixa.
 */
class IncomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost');
        $this->travelTo(Carbon::parse('2026-10-15 15:00:00', 'America/Sao_Paulo'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Master]));
    }

    public function test_creates_expected_and_already_received_incomes(): void
    {
        $this->postJson('/api/v1/incomes', ['description' => 'Prêmio', 'category' => 'other', 'amount_cents' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_cents', 'expected_on']);

        $this->postJson('/api/v1/incomes', [
            'description' => 'Venda do elevador antigo',
            'category' => 'asset_sale',
            'amount_cents' => 450000,
            'expected_on' => '2026-10-20',
        ])->assertCreated()->assertJsonPath('data.received_at', null)->assertJsonPath('data.is_late', false);

        // Já recebida: a data prevista vira o próprio dia
        $this->postJson('/api/v1/incomes', [
            'description' => '  Ganhei   na Mega-Sena ',
            'category' => 'other',
            'amount_cents' => 1000000,
            'received_at' => '2026-10-10',
            'payment_method' => 'pix',
        ])->assertCreated()
            ->assertJsonPath('data.description', 'Ganhei na Mega-Sena')
            ->assertJsonPath('data.expected_on', '2026-10-10')
            ->assertJsonPath('data.payment_method_label', 'Pix');

        $this->getJson('/api/v1/incomes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.description', 'Venda do elevador antigo') // pendentes primeiro
            ->assertJsonPath('summary.pending.cents', 450000)
            ->assertJsonPath('summary.received_this_month', 1000000)
            ->assertJsonPath('options.categories.owner_contribution', 'Aporte do dono');

        $this->getJson('/api/v1/incomes?status=received')->assertJsonCount(1, 'data');
    }

    public function test_receive_and_reverse(): void
    {
        $income = Income::create(['description' => 'Empréstimo', 'category' => 'loan', 'amount_cents' => 200000, 'expected_on' => '2026-10-12']);

        $this->getJson('/api/v1/incomes')->assertJsonPath('data.0.is_late', true)->assertJsonPath('summary.late.count', 1);

        $this->postJson("/api/v1/incomes/{$income->id}/receive", ['received_at' => '2026-10-16', 'payment_method' => 'bank_transfer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['received_at']); // futura

        $this->postJson("/api/v1/incomes/{$income->id}/receive", ['received_at' => '2026-10-14', 'payment_method' => 'bank_transfer', 'amount_cents' => 195000])
            ->assertOk()
            ->assertJsonPath('data.received_at', '2026-10-14')
            ->assertJsonPath('data.amount_cents', 195000);

        // Recebida não muda de valor nem é excluída por quem não é master
        $this->putJson("/api/v1/incomes/{$income->id}", ['description' => 'Empréstimo', 'category' => 'loan', 'amount_cents' => 1, 'expected_on' => '2026-10-12'])
            ->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->deleteJson("/api/v1/incomes/{$income->id}")->assertForbidden();
        $this->postJson("/api/v1/incomes/{$income->id}/unreceive")->assertForbidden();

        $this->actingAs(User::where('role', UserRole::Master)->first());
        $this->postJson("/api/v1/incomes/{$income->id}/unreceive")->assertOk()->assertJsonPath('data.received_at', null);
        $this->deleteJson("/api/v1/incomes/{$income->id}")->assertNoContent();
    }

    public function test_cash_flow_counts_received_and_projects_pending(): void
    {
        $this->putJson('/api/v1/settings/finance', ['opening_balance_cents' => 100000, 'opening_date' => '2026-10-01'])->assertOk();
        Income::create(['description' => 'Aporte', 'category' => 'owner_contribution', 'amount_cents' => 50000, 'expected_on' => '2026-10-05'])
            ->forceFill(['received_at' => '2026-10-05', 'payment_method' => 'pix'])->save();
        Income::create(['description' => 'Venda de sucata', 'category' => 'asset_sale', 'amount_cents' => 30000, 'expected_on' => '2026-10-25']);
        Income::create(['description' => 'Reembolso atrasado', 'category' => 'refund', 'amount_cents' => 7000, 'expected_on' => '2026-10-02']);

        $flow = $this->getJson('/api/v1/cash-flow?month=2026-10')->assertOk()->json('data');

        $this->assertSame(50000, $flow['days'][4]['in_cents']);              // 05/10
        $this->assertSame(7000, $flow['days'][14]['planned_in_cents']);      // atrasada cai em hoje (15/10)
        $this->assertSame(30000, $flow['days'][24]['planned_in_cents']);     // 25/10
        $this->assertSame(50000, $flow['summary']['received_other_cents']);
        $this->assertSame(0, $flow['summary']['received_orders_cents']);
        $this->assertSame(37000, $flow['summary']['to_receive_other_cents']);
        $this->assertSame(150000, $flow['summary']['current_balance_cents']); // 1.000 + 500
        $this->assertSame(187000, $flow['summary']['projected_end_cents']);   // + 70 + 300 previstos
        $this->assertSame(187000, end($flow['days'])['balance_cents']);
    }
}
