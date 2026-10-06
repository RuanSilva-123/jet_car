<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Enums\ReminderStatus;
use App\Enums\ServiceOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Bill;
use App\Models\Part;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderPayment;
use App\Models\ServiceReminder;
use App\Services\Finance\CashFlow;
use App\Support\BackupStatus;
use App\Support\Nps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Indicadores da oficina: OS por status, atrasadas, orçamentos sem resposta, prontas para
 * retirada, faturamento do mês (só para quem acessa o financeiro), agenda do dia, lembretes
 * e estoque baixo. Datas "de hoje" e "do mês" no fuso da oficina.
 */
class DashboardController extends Controller
{
    private const LIST_LIMIT = 6;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Orçamento enviado há mais de X dias sem resposta
            'budget_days' => ['nullable', 'integer', 'between:1,60'],
        ]);
        $budgetDays = (int) ($data['budget_days'] ?? 3);

        $tz = (string) config('jetcar.timezone');
        $now = now()->timezone($tz);
        $today = $now->toDateString();

        $counts = ServiceOrder::query()
            ->whereIn('status', ServiceOrderStatus::activeValues())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $statusCounts = collect(ServiceOrderStatus::cases())
            ->filter(fn (ServiceOrderStatus $status) => ! $status->isFinal())
            ->map(fn (ServiceOrderStatus $status) => [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($counts[$status->value] ?? 0),
            ])->values();

        // Atrasadas: previsão vencida e ainda não pronta
        $overdue = ServiceOrder::query()
            ->whereIn('status', [ServiceOrderStatus::Open->value, ServiceOrderStatus::WaitingApproval->value, ServiceOrderStatus::InProgress->value, ServiceOrderStatus::WaitingParts->value])
            ->whereNotNull('expected_at')
            ->whereDate('expected_at', '<', $today);

        $staleBudgets = ServiceOrder::query()
            ->where('status', ServiceOrderStatus::WaitingApproval->value)
            ->where('budget_sent_at', '<=', $now->copy()->subDays($budgetDays)->utc());

        $ready = ServiceOrder::query()->where('status', ServiceOrderStatus::Completed->value);

        $appointments = Appointment::query()
            ->with(['customer', 'vehicle'])
            ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value])
            ->whereBetween('scheduled_at', [$now->copy()->startOfDay()->utc(), $now->copy()->endOfDay()->utc()])
            ->orderBy('scheduled_at')
            ->get();

        return response()->json(['data' => [
            'generated_at' => now()->toIso8601String(),
            'budget_days' => $budgetDays,
            'status_counts' => $statusCounts,
            'active_total' => (int) $statusCounts->sum('count'),
            'overdue' => $this->orderList($overdue, 'expected_at'),
            'stale_budgets' => $this->orderList($staleBudgets, 'budget_sent_at'),
            'ready_for_pickup' => $this->orderList($ready, 'completed_at'),
            'finance' => $request->user()->canManageFinance() ? $this->finance($now) : null,
            'appointments_today' => [
                'count' => $appointments->count(),
                'items' => $appointments->take(self::LIST_LIMIT)->map(fn (Appointment $appointment) => [
                    'id' => $appointment->id,
                    'scheduled_at' => $appointment->scheduled_at->toIso8601String(),
                    'status' => $appointment->status->value,
                    'customer' => $appointment->displayName(),
                    'vehicle' => $appointment->vehicleLabel(),
                    'plate' => $appointment->vehicle?->plate,
                ])->values(),
            ],
            'reminders_pending' => ServiceReminder::whereIn('status', ReminderStatus::openValues())->count(),
            'satisfaction' => $this->satisfaction(),
            // Só o master vê (é quem resolve): aviso quando o backup falhou ou parou
            'backup' => $request->user()->isMaster() ? BackupStatus::get() : null,
            'warranty_returns_month' => ServiceOrder::query()
                ->whereNotNull('warranty_of_id')
                ->where('status', '!=', ServiceOrderStatus::Canceled->value)
                ->where('created_at', '>=', $now->copy()->startOfMonth()->utc())
                ->count(),
            'low_stock' => [
                'count' => Part::lowStock()->count(),
                'items' => Part::lowStock()->orderBy('stock_quantity')->limit(5)->get()->map(fn (Part $part) => [
                    'id' => $part->id,
                    'name' => $part->name,
                    'unit' => $part->unit,
                    'stock_quantity' => (float) $part->stock_quantity,
                    'min_stock' => (float) $part->min_stock,
                ]),
            ],
        ]]);
    }

    /**
     * NPS dos últimos 90 dias, as avaliações mais recentes e quantas entregas esperam avaliação.
     *
     * @return array<string, mixed>
     */
    private function satisfaction(): array
    {
        $since = now()->subDays(90);
        $answered = ServiceOrder::query()->whereNotNull('survey_answered_at')->where('survey_answered_at', '>=', $since);

        return [
            ...Nps::summarize((clone $answered)->pluck('survey_score')),
            'days' => 90,
            'recent' => (clone $answered)->with('customer')->latest('survey_answered_at')->limit(3)->get()->map(fn (ServiceOrder $order) => [
                'id' => $order->id,
                'number' => $order->number(),
                'customer' => $order->customer->trade_name ?: $order->customer->name,
                'score' => $order->survey_score,
                'comment' => $order->survey_comment,
                'answered_at' => $order->survey_answered_at->toIso8601String(),
            ]),
            // Entregues nos últimos 30 dias que ainda não avaliaram (para pedir a avaliação)
            'awaiting' => ServiceOrder::query()
                ->where('status', ServiceOrderStatus::Delivered->value)
                ->whereNull('survey_answered_at')
                ->where('delivered_at', '>=', now()->subDays(30))
                ->count(),
        ];
    }

    /**
     * @param  Builder<ServiceOrder>  $query
     * @return array{count: int, items: mixed}
     */
    private function orderList(Builder $query, string $dateColumn): array
    {
        return [
            'count' => (clone $query)->count(),
            'items' => (clone $query)
                ->with(['customer', 'vehicle'])
                ->orderBy($dateColumn)
                ->limit(self::LIST_LIMIT)
                ->get()
                ->map(fn (ServiceOrder $order) => [
                    'id' => $order->id,
                    'number' => $order->number(),
                    'status' => $order->status->value,
                    'customer' => $order->customer->trade_name ?: $order->customer->name,
                    'phone' => $order->customer->phone,
                    'phone_is_whatsapp' => $order->customer->phone_is_whatsapp,
                    'vehicle' => trim($order->vehicle->brand.' '.$order->vehicle->model),
                    'plate' => $order->vehicle->plate,
                    'total_cents' => $order->total_cents,
                    'date' => match (true) {
                        $order->{$dateColumn} === null => null,
                        $dateColumn === 'expected_at' => $order->expected_at->toDateString(),
                        default => $order->{$dateColumn}->toIso8601String(),
                    },
                ]),
        ];
    }

    /**
     * Faturamento = OS entregues no mês (ticket médio sobre elas); recebido = pagamentos do mês.
     *
     * @return array<string, mixed>
     */
    private function finance(Carbon $now): array
    {
        $delivered = fn ($start, $end) => ServiceOrder::query()
            ->where('status', ServiceOrderStatus::Delivered->value)
            ->whereBetween('delivered_at', [$start->copy()->utc(), $end->copy()->utc()]);

        $monthStart = $now->copy()->startOfMonth();
        $current = $delivered($monthStart, $now->copy()->endOfDay());
        $count = (clone $current)->count();
        $revenue = (int) (clone $current)->sum('total_cents');

        // Mês anterior até o mesmo dia, para a comparação ser justa
        $previousStart = $monthStart->copy()->subMonthNoOverflow();
        $previousEnd = $previousStart->copy()->addDays($now->day - 1)->endOfDay()->min($previousStart->copy()->endOfMonth());
        $previousRevenue = (int) $delivered($previousStart, $previousEnd)->sum('total_cents');

        $receivable = ServiceOrder::query()
            ->whereNotNull('budget_approved_at')
            ->where('status', '!=', ServiceOrderStatus::Canceled->value)
            ->whereColumn('total_cents', '>', 'paid_cents');

        return [
            'month' => $monthStart->format('Y-m'),
            'revenue_cents' => $revenue,
            'delivered_count' => $count,
            'average_ticket_cents' => $count ? intdiv($revenue, $count) : 0,
            'previous_revenue_cents' => $previousRevenue,
            'received_cents' => (int) ServiceOrderPayment::query()
                ->whereDate('paid_at', '>=', $monthStart->toDateString())
                ->whereDate('paid_at', '<=', $now->toDateString())
                ->sum('amount_cents'),
            'receivable_cents' => (int) (clone $receivable)->sum('total_cents') - (int) (clone $receivable)->sum('paid_cents'),
            'receivable_count' => (clone $receivable)->count(),
            // Contas a pagar: vencidas e próximos 7 dias; saldo do caixa hoje
            'bills_overdue_count' => Bill::query()->open()->whereDate('due_date', '<', $now->toDateString())->count(),
            'bills_overdue_cents' => (int) Bill::query()->open()->whereDate('due_date', '<', $now->toDateString())->sum('amount_cents'),
            'bills_due_soon_count' => Bill::query()->open()->whereDate('due_date', '>=', $now->toDateString())->whereDate('due_date', '<=', $now->copy()->addDays(7)->toDateString())->count(),
            'bills_due_soon_cents' => (int) Bill::query()->open()->whereDate('due_date', '>=', $now->toDateString())->whereDate('due_date', '<=', $now->copy()->addDays(7)->toDateString())->sum('amount_cents'),
            'cash_balance_cents' => app(CashFlow::class)->balanceBefore($now->copy()->addDay()->startOfDay()),
            'daily' => $this->daily($now),
        ];
    }

    /**
     * Últimos 30 dias, dia a dia (fuso da oficina): faturamento das OS entregues e o que entrou no caixa.
     * Dias sem movimento vêm com zero, para o gráfico ter o eixo do tempo contínuo.
     *
     * @return list<array{date: string, revenue_cents: int, orders: int, received_cents: int}>
     */
    private function daily(Carbon $now): array
    {
        $start = $now->copy()->subDays(29)->startOfDay();
        $timezone = $now->getTimezone();

        $orders = ServiceOrder::query()
            ->where('status', ServiceOrderStatus::Delivered->value)
            ->where('delivered_at', '>=', $start->copy()->utc())
            ->get(['delivered_at', 'total_cents'])
            ->groupBy(fn (ServiceOrder $order) => $order->delivered_at->copy()->setTimezone($timezone)->toDateString());

        $received = ServiceOrderPayment::query()
            ->whereDate('paid_at', '>=', $start->toDateString())
            ->get(['paid_at', 'amount_cents'])
            ->groupBy(fn (ServiceOrderPayment $payment) => $payment->paid_at->toDateString());

        $days = [];
        for ($day = $start->copy(); $day->lte($now); $day->addDay()) {
            $key = $day->toDateString();
            $days[] = [
                'date' => $key,
                'revenue_cents' => (int) ($orders->get($key)?->sum('total_cents') ?? 0),
                'orders' => $orders->get($key)?->count() ?? 0,
                'received_cents' => (int) ($received->get($key)?->sum('amount_cents') ?? 0),
            ];
        }

        return $days;
    }
}
