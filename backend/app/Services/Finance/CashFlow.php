<?php

namespace App\Services\Finance;

use App\Enums\ServiceOrderStatus;
use App\Models\Bill;
use App\Models\Income;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderPayment;
use App\Support\FinanceSettings;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fluxo de caixa do mês: entradas (recebimentos das OS e outras entradas) e saídas (contas
 * pagas) dia a dia, com o saldo acumulado. Do dia de hoje em diante, as contas em aberto entram
 * como saída prevista e as outras entradas pendentes como entrada prevista (atrasadas contam
 * hoje), e o saldo vira projeção.
 *
 * Saldo = saldo inicial informado + tudo que entrou − tudo que saiu desde a data dele.
 */
class CashFlow
{
    /**
     * @return array<string, mixed>
     */
    public function month(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth()->startOfDay();
        $end = $month->copy()->endOfMonth()->startOfDay();
        $today = LocalTime::now()->startOfDay();

        $opening = $this->balanceBefore($start);

        $received = ServiceOrderPayment::query()
            ->whereDate('paid_at', '>=', $start->toDateString())
            ->whereDate('paid_at', '<=', $end->toDateString())
            ->get(['paid_at', 'amount_cents'])
            ->groupBy(fn (ServiceOrderPayment $payment) => $payment->paid_at->toDateString())
            ->map->sum('amount_cents');

        // Outras entradas (não vêm de OS): recebidas no mês e as previstas até o fim dele
        $otherReceived = Income::query()
            ->whereNotNull('received_at')
            ->whereDate('received_at', '>=', $start->toDateString())
            ->whereDate('received_at', '<=', $end->toDateString())
            ->get(['received_at', 'amount_cents'])
            ->groupBy(fn (Income $income) => $income->received_at->toDateString())
            ->map->sum('amount_cents');
        $pendingIncomes = $end->lt($today) ? collect() : Income::query()->pending()->whereDate('expected_on', '<=', $end->toDateString())->get();
        $plannedIn = $pendingIncomes
            ->groupBy(fn (Income $income) => $income->expected_on->lt($today) ? $today->toDateString() : $income->expected_on->toDateString())
            ->map->sum('amount_cents');

        $paidBills = Bill::query()
            ->whereNotNull('paid_at')
            ->whereDate('paid_at', '>=', $start->toDateString())
            ->whereDate('paid_at', '<=', $end->toDateString())
            ->get();
        $paid = $paidBills->groupBy(fn (Bill $bill) => $bill->paid_at->toDateString())->map->sum('amount_cents');

        // Contas em aberto que vencem até o fim do mês (as vencidas antes de hoje caem em "hoje")
        $openBills = Bill::query()->open()->whereDate('due_date', '<=', $end->toDateString())->get();
        $planned = $end->lt($today) ? collect() : $openBills
            ->groupBy(fn (Bill $bill) => $bill->due_date->lt($today) ? $today->toDateString() : $bill->due_date->toDateString())
            ->map->sum('amount_cents');

        $days = [];
        $balance = $opening;
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $key = $day->toDateString();
            $in = (int) ($received[$key] ?? 0) + (int) ($otherReceived[$key] ?? 0);
            $out = (int) ($paid[$key] ?? 0);
            $plannedOut = $day->gte($today) ? (int) ($planned[$key] ?? 0) : 0;
            $plannedInDay = $day->gte($today) ? (int) ($plannedIn[$key] ?? 0) : 0;
            $balance += $in + $plannedInDay - $out - $plannedOut;

            $days[] = [
                'date' => $key,
                'in_cents' => $in,
                'planned_in_cents' => $plannedInDay,
                'out_cents' => $out,
                'planned_out_cents' => $plannedOut,
                'balance_cents' => $balance,
                'projected' => $day->gt($today),
            ];
        }

        $ordersReceived = (int) $received->sum();
        $otherReceivedTotal = (int) $otherReceived->sum();
        $receivedTotal = $ordersReceived + $otherReceivedTotal;
        $toReceiveOther = (int) $pendingIncomes->sum('amount_cents');
        $paidTotal = (int) $paid->sum();
        $toPay = $end->lt($today) ? 0 : (int) $openBills->sum('amount_cents');
        $overdue = Bill::query()->open()->whereDate('due_date', '<', $today->toDateString());

        $receivable = ServiceOrder::query()
            ->whereNotNull('budget_approved_at')
            ->where('status', '!=', ServiceOrderStatus::Canceled->value)
            ->whereColumn('total_cents', '>', 'paid_cents');
        $receivableCents = (int) (clone $receivable)->sum('total_cents') - (int) (clone $receivable)->sum('paid_cents');

        // Saldo de hoje (para mês passado, o do último dia; para mês futuro, o do início)
        $current = match (true) {
            $end->lt($today) => $opening + $receivedTotal - $paidTotal,
            $start->gt($today) => $opening,
            default => $this->balanceBefore($today->copy()->addDay()),
        };
        $projectedEnd = $opening + $receivedTotal - $paidTotal - $toPay + $toReceiveOther;

        return [
            'month' => $start->format('Y-m'),
            'opening_balance_cents' => $opening,
            'days' => $days,
            'by_category' => $this->byCategory($paidBills, $end->lt($today) ? collect() : $openBills),
            'summary' => [
                'received_cents' => $receivedTotal,
                'received_orders_cents' => $ordersReceived,
                'received_other_cents' => $otherReceivedTotal,
                // Outras entradas ainda previstas no mês (entram na previsão do fim do mês)
                'to_receive_other_cents' => $toReceiveOther,
                'to_receive_other_count' => $pendingIncomes->count(),
                'paid_cents' => $paidTotal,
                'net_cents' => $receivedTotal - $paidTotal,
                'current_balance_cents' => $current,
                'to_pay_cents' => $toPay,
                'to_pay_count' => $end->lt($today) ? 0 : $openBills->count(),
                'overdue_cents' => (int) (clone $overdue)->sum('amount_cents'),
                'overdue_count' => (clone $overdue)->count(),
                'receivable_cents' => $receivableCents,
                'projected_end_cents' => $projectedEnd,
                'projected_with_receivables_cents' => $projectedEnd + $receivableCents,
            ],
            'settings' => FinanceSettings::get(),
        ];
    }

    /** Saldo no começo do dia $day. O saldo inicial vale a partir do começo da data informada. */
    public function balanceBefore(Carbon $day): int
    {
        $settings = FinanceSettings::get();
        $since = $settings['opening_date'];

        $in = ServiceOrderPayment::query()
            ->when($since, fn ($query) => $query->whereDate('paid_at', '>=', $since))
            ->whereDate('paid_at', '<', $day->toDateString())
            ->sum('amount_cents');
        $other = Income::query()
            ->whereNotNull('received_at')
            ->when($since, fn ($query) => $query->whereDate('received_at', '>=', $since))
            ->whereDate('received_at', '<', $day->toDateString())
            ->sum('amount_cents');
        $out = Bill::query()
            ->whereNotNull('paid_at')
            ->when($since, fn ($query) => $query->whereDate('paid_at', '>=', $since))
            ->whereDate('paid_at', '<', $day->toDateString())
            ->sum('amount_cents');

        $opening = $since === null || $day->toDateString() >= $since ? $settings['opening_balance_cents'] : 0;

        return $opening + (int) $in + (int) $other - (int) $out;
    }

    /**
     * Saídas do mês por categoria: pagas e ainda a pagar.
     *
     * @param  Collection<int, Bill>  $paid
     * @param  Collection<int, Bill>  $open
     * @return list<array{category: string, label: string, paid_cents: int, open_cents: int}>
     */
    private function byCategory($paid, $open): array
    {
        return $paid->concat($open)
            ->groupBy(fn (Bill $bill) => $bill->category->value)
            ->map(fn ($bills) => [
                'category' => $bills->first()->category->value,
                'label' => $bills->first()->category->label(),
                'paid_cents' => (int) $bills->filter->isPaid()->sum('amount_cents'),
                'open_cents' => (int) $bills->reject->isPaid()->sum('amount_cents'),
            ])
            ->sortByDesc(fn (array $row) => $row['paid_cents'] + $row['open_cents'])
            ->values()
            ->all();
    }
}
