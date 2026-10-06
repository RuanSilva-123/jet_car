<?php

namespace Tests\Feature\Satisfaction;

use App\Enums\ServiceOrderStatus;
use App\Enums\UserRole;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Support\BudgetLink;
use App\Support\Nps;
use App\Support\SurveyLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pesquisa de satisfação: link após a entrega, uma resposta por OS, NPS no dashboard e relatório.
 */
class SatisfactionSurveyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost');
    }

    private function delivered(): ServiceOrder
    {
        $order = ServiceOrder::factory()->status(ServiceOrderStatus::Delivered)->create();
        $order->forceFill(['delivered_at' => now()->subDay()])->save();

        return $order;
    }

    private function path(ServiceOrder $order): string
    {
        return '/api/v1/public/surveys/'.SurveyLink::make($order)['token'];
    }

    public function test_nps_math(): void
    {
        // 3 promotores, 1 neutro, 1 detrator: (3 - 1) / 5 = 40
        $summary = Nps::summarize([10, 9, 9, 8, 3]);
        $this->assertSame(40, $summary['nps']);
        $this->assertSame(7.8, $summary['average']);
        $this->assertSame(1, $summary['passives']);
        $this->assertNull(Nps::summarize([])['nps']);
    }

    public function test_detail_offers_survey_link_after_delivery(): void
    {
        $this->actingAs(User::factory()->create());
        $open = ServiceOrder::factory()->create();
        $delivered = $this->delivered();

        $this->getJson("/api/v1/service-orders/{$open->id}")->assertJsonPath('data.survey_url', null);
        $url = $this->getJson("/api/v1/service-orders/{$delivered->id}")->json('data.survey_url');
        $this->assertStringContainsString('/avaliacao/'.$delivered->id.'.', $url);
    }

    public function test_customer_answers_once(): void
    {
        $order = $this->delivered();

        $this->getJson($this->path($order))->assertOk()->assertJsonPath('data.state', 'open')->assertJsonMissingPath('data.order.customer.phone');

        $this->postJson($this->path($order), ['score' => 9, 'comment' => 'Atendimento ótimo'])
            ->assertOk()
            ->assertJsonPath('data.state', 'answered')
            ->assertJsonPath('data.score', 9);

        $this->postJson($this->path($order), ['score' => 2])->assertUnprocessable();

        $order->refresh();
        $this->assertSame(9, $order->survey_score);
        $this->assertSame('survey_answered', $order->events()->first()->type);
        $this->assertNull($order->events()->first()->user_id);
    }

    public function test_score_must_be_between_zero_and_ten(): void
    {
        $this->postJson($this->path($this->delivered()), ['score' => 11])->assertUnprocessable()->assertJsonValidationErrors('score');
    }

    public function test_budget_link_cannot_be_used_as_survey_link(): void
    {
        $order = $this->delivered();
        $budgetToken = BudgetLink::make($order)['token'];

        $this->getJson("/api/v1/public/surveys/{$budgetToken}")->assertNotFound();
    }

    public function test_not_delivered_order_cannot_be_rated(): void
    {
        $order = ServiceOrder::factory()->create();

        $this->getJson($this->path($order))->assertJsonPath('data.state', 'unavailable');
        $this->postJson($this->path($order), ['score' => 10])->assertUnprocessable();
    }

    public function test_dashboard_and_report_show_nps(): void
    {
        foreach ([10, 9, 4] as $score) {
            $this->postJson($this->path($this->delivered()), ['score' => $score, 'comment' => "nota {$score}"])->assertOk();
        }
        $this->delivered(); // ainda sem avaliação

        $this->actingAs(User::factory()->create(['role' => UserRole::Master]));
        $this->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.satisfaction.responses', 3)
            ->assertJsonPath('data.satisfaction.nps', 33)
            ->assertJsonPath('data.satisfaction.awaiting', 1);

        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();
        $this->getJson("/api/v1/reports/satisfaction?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('data.rows.0.score', 4) // pior nota primeiro
            ->assertJsonPath('data.summary.nps', 33);

        $this->get("/api/v1/reports/satisfaction?from={$from}&to={$to}&format=csv")->assertOk();
    }
}
