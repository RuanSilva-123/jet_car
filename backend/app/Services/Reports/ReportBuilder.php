<?php

namespace App\Services\Reports;

use App\Enums\ServiceOrderStatus;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\ServiceOrderPayment;
use App\Models\User;
use App\Support\Nps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Relatórios gerenciais. Cada relatório devolve colunas tipadas, linhas e totais; o mesmo
 * resultado vira JSON (tela) ou CSV (exportação). Faturamento = OS entregues no período,
 * pela data de entrega no fuso da oficina (config jetcar.timezone).
 *
 * Os agrupamentos são feitos em PHP para funcionar igual no PostgreSQL e no SQLite dos testes;
 * o volume de uma oficina (milhares de OS por ano) cabe tranquilamente.
 */
class ReportBuilder
{
    public const REPORTS = [
        'revenue' => 'Faturamento por período',
        'services' => 'Serviços mais vendidos',
        'customers' => 'Clientes e retorno',
        'mechanics' => 'Produção dos mecânicos',
        'warranty' => 'Retornos em garantia',
        'satisfaction' => 'Satisfação dos clientes',
    ];

    private string $timezone;

    public function __construct()
    {
        $this->timezone = (string) config('jetcar.timezone');
    }

    /**
     * @param  string  $from  data inicial (Y-m-d)
     * @param  string  $to  data final (Y-m-d)
     * @param  array{group?: string, only_returning?: bool}  $options
     * @return array{report: string, title: string, from: string, to: string,
     *     columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>,
     *     totals: array<string, mixed>|null, summary: array<string, mixed>}
     */
    public function build(string $report, string $from, string $to, array $options = []): array
    {
        // Datas do período no fuso da oficina: "1º de outubro" começa à meia-noite daqui, não de Greenwich
        $start = Carbon::parse($from, $this->timezone)->startOfDay();
        $end = Carbon::parse($to, $this->timezone)->endOfDay();

        $result = match ($report) {
            'revenue' => $this->revenue($start, $end, $options['group'] ?? 'day'),
            'services' => $this->services($start, $end),
            'customers' => $this->customers($start, $end, (bool) ($options['only_returning'] ?? false)),
            'mechanics' => $this->mechanics($start, $end),
            'warranty' => $this->warranty($start, $end),
            'satisfaction' => $this->satisfaction($start, $end),
        };

        return [
            'report' => $report,
            'title' => self::REPORTS[$report],
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            ...$result,
        ];
    }

    /** OS entregues no período (base de faturamento). */
    private function deliveredOrders(Carbon $start, Carbon $end): Collection
    {
        return ServiceOrder::query()
            ->where('status', ServiceOrderStatus::Delivered->value)
            ->whereBetween('delivered_at', [$start->copy()->utc(), $end->copy()->utc()])
            ->get();
    }

    private function revenue(Carbon $start, Carbon $end, string $group): array
    {
        $format = $group === 'month' ? 'Y-m' : 'Y-m-d';
        $local = fn (?Carbon $date) => $date?->copy()->timezone($this->timezone)->format($format);

        $orders = $this->deliveredOrders($start, $end)->groupBy(fn (ServiceOrder $order) => $local($order->delivered_at));
        $received = ServiceOrderPayment::query()
            ->whereDate('paid_at', '>=', $start->toDateString())
            ->whereDate('paid_at', '<=', $end->toDateString())
            ->get()
            ->groupBy(fn (ServiceOrderPayment $payment) => $payment->paid_at->format($format));

        $periods = $orders->keys()->merge($received->keys())->unique()->sort()->values();

        $rows = $periods->map(function (string $period) use ($orders, $received, $group) {
            $list = $orders->get($period, collect());
            $total = (int) $list->sum('total_cents');

            return [
                'period' => $group === 'month' ? $period.'-01' : $period,
                'orders' => $list->count(),
                'labor_cents' => (int) $list->sum('labor_total_cents'),
                'parts_cents' => (int) $list->sum('parts_total_cents'),
                'discount_cents' => (int) $list->sum('discount_cents'),
                'total_cents' => $total,
                'average_cents' => $list->count() ? intdiv($total, $list->count()) : 0,
                'received_cents' => (int) $received->get($period, collect())->sum('amount_cents'),
            ];
        })->values();

        $count = (int) $rows->sum('orders');
        $total = (int) $rows->sum('total_cents');

        return [
            'columns' => [
                ['key' => 'period', 'label' => $group === 'month' ? 'Mês' : 'Dia', 'type' => $group === 'month' ? 'month' : 'date'],
                ['key' => 'orders', 'label' => 'OS entregues', 'type' => 'int'],
                ['key' => 'labor_cents', 'label' => 'Mão de obra', 'type' => 'money'],
                ['key' => 'parts_cents', 'label' => 'Peças', 'type' => 'money'],
                ['key' => 'discount_cents', 'label' => 'Descontos', 'type' => 'money'],
                ['key' => 'total_cents', 'label' => 'Faturamento', 'type' => 'money'],
                ['key' => 'average_cents', 'label' => 'Ticket médio', 'type' => 'money'],
                ['key' => 'received_cents', 'label' => 'Recebido', 'type' => 'money'],
            ],
            'rows' => $rows->all(),
            'totals' => [
                'period' => 'Total',
                'orders' => $count,
                'labor_cents' => (int) $rows->sum('labor_cents'),
                'parts_cents' => (int) $rows->sum('parts_cents'),
                'discount_cents' => (int) $rows->sum('discount_cents'),
                'total_cents' => $total,
                'average_cents' => $count ? intdiv($total, $count) : 0,
                'received_cents' => (int) $rows->sum('received_cents'),
            ],
            'summary' => ['orders' => $count, 'total_cents' => $total, 'average_cents' => $count ? intdiv($total, $count) : 0],
        ];
    }

    private function services(Carbon $start, Carbon $end): array
    {
        $orderIds = $this->deliveredOrders($start, $end)->pluck('id');

        $rows = ServiceOrderItem::query()
            ->whereIn('service_order_id', $orderIds)
            ->where('is_done', true)
            // Serviço refeito na garantia não é venda
            ->whereNull('warranty_of_item_id')
            ->get()
            ->groupBy(fn (ServiceOrderItem $item) => $this->serviceKey($item))
            ->map(function (Collection $items) {
                $revenue = (int) $items->sum('price_cents');

                return [
                    'name' => $items->last()->name,
                    'count' => $items->count(),
                    'revenue_cents' => $revenue,
                    'average_cents' => intdiv($revenue, $items->count()),
                ];
            })
            ->sortBy([['count', 'desc'], ['revenue_cents', 'desc']])
            ->values();

        $total = (int) $rows->sum('revenue_cents');
        $rows = $rows->map(fn (array $row) => [...$row, 'share' => $total ? round($row['revenue_cents'] / $total * 100, 1) : 0.0]);

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Serviço', 'type' => 'text'],
                ['key' => 'count', 'label' => 'Vezes feito', 'type' => 'int'],
                ['key' => 'revenue_cents', 'label' => 'Faturamento', 'type' => 'money'],
                ['key' => 'average_cents', 'label' => 'Preço médio', 'type' => 'money'],
                ['key' => 'share', 'label' => '% do faturamento', 'type' => 'percent'],
            ],
            'rows' => $rows->all(),
            'totals' => ['name' => 'Total', 'count' => (int) $rows->sum('count'), 'revenue_cents' => $total, 'average_cents' => null, 'share' => $total ? 100.0 : 0.0],
            'summary' => ['services' => $rows->count(), 'executions' => (int) $rows->sum('count')],
        ];
    }

    private function customers(Carbon $start, Carbon $end, bool $onlyReturning): array
    {
        $inPeriod = $this->deliveredOrders($start, $end)->groupBy('customer_id');

        // Histórico completo desses clientes: primeira visita e se já tinham vindo antes do período
        $history = ServiceOrder::query()
            ->where('status', ServiceOrderStatus::Delivered->value)
            ->whereIn('customer_id', $inPeriod->keys())
            ->with('customer')
            ->get()
            ->groupBy('customer_id');

        $rows = $inPeriod->map(function (Collection $orders, int $customerId) use ($history, $start) {
            $all = $history->get($customerId, collect());
            $customer = $all->first()?->customer;
            $first = $all->min('delivered_at');

            return [
                'name' => $customer ? ($customer->trade_name ?: $customer->name) : '—',
                'phone' => $customer?->phone,
                'orders' => $orders->count(),
                'total_cents' => (int) $orders->sum('total_cents'),
                'lifetime_orders' => $all->count(),
                'lifetime_cents' => (int) $all->sum('total_cents'),
                'first_visit' => $first?->copy()->timezone($this->timezone)->toDateString(),
                'last_visit' => $orders->max('delivered_at')?->copy()->timezone($this->timezone)->toDateString(),
                'returning' => $all->contains(fn (ServiceOrder $order) => $order->delivered_at->lt($start->copy()->utc())) || $orders->count() > 1,
            ];
        })
            ->when($onlyReturning, fn (Collection $rows) => $rows->where('returning', true))
            ->sortBy([['orders', 'desc'], ['total_cents', 'desc']])
            ->values();

        $customers = $rows->count();
        $returning = $rows->where('returning', true)->count();

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Cliente', 'type' => 'text'],
                ['key' => 'phone', 'label' => 'Telefone', 'type' => 'phone'],
                ['key' => 'orders', 'label' => 'OS no período', 'type' => 'int'],
                ['key' => 'total_cents', 'label' => 'Gasto no período', 'type' => 'money'],
                ['key' => 'lifetime_orders', 'label' => 'OS desde sempre', 'type' => 'int'],
                ['key' => 'lifetime_cents', 'label' => 'Gasto desde sempre', 'type' => 'money'],
                ['key' => 'first_visit', 'label' => 'Primeira visita', 'type' => 'date'],
                ['key' => 'last_visit', 'label' => 'Última visita', 'type' => 'date'],
                ['key' => 'returning', 'label' => 'Voltou', 'type' => 'bool'],
            ],
            'rows' => $rows->all(),
            'totals' => null,
            'summary' => [
                'customers' => $customers,
                'returning' => $returning,
                'returning_rate' => $customers ? round($returning / $customers * 100, 1) : 0.0,
            ],
        ];
    }

    /**
     * Quais serviços mais voltam: retornos abertos no período por serviço, comparados com
     * quantas vezes o serviço foi entregue no mesmo período, e quem tinha feito o serviço.
     */
    private function warranty(Carbon $start, Carbon $end): array
    {
        $returns = ServiceOrderItem::query()
            ->whereNotNull('warranty_of_item_id')
            ->whereHas('serviceOrder', fn ($query) => $query
                ->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->utc()])
                ->where('status', '!=', ServiceOrderStatus::Canceled->value))
            ->with(['warrantyOf.mechanic'])
            ->get();

        $deliveredIds = $this->deliveredOrders($start, $end)->pluck('id');
        $executions = ServiceOrderItem::query()
            ->whereIn('service_order_id', $deliveredIds)
            ->where('is_done', true)
            ->whereNull('warranty_of_item_id')
            ->get()
            ->groupBy(fn (ServiceOrderItem $item) => $this->serviceKey($item))
            ->map->count();

        $rows = $returns
            ->groupBy(fn (ServiceOrderItem $item) => $this->serviceKey($item))
            ->map(function (Collection $items, string $key) use ($executions) {
                $done = (int) ($executions[$key] ?? 0);
                $mechanics = $items->map(fn (ServiceOrderItem $item) => $item->warrantyOf?->mechanic?->name)->filter()->countBy()
                    ->map(fn (int $count, string $name) => $count > 1 ? "{$name} ({$count})" : $name)->implode(', ');

                return [
                    'name' => $items->last()->name,
                    'returns' => $items->count(),
                    'executions' => $done,
                    'rate' => $done ? round($items->count() / $done * 100, 1) : null,
                    'mechanics' => $mechanics ?: null,
                ];
            })
            ->sortByDesc('returns')
            ->values();

        $orders = $returns->pluck('service_order_id')->unique()->count();
        $delivered = $deliveredIds->count();

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Serviço', 'type' => 'text'],
                ['key' => 'returns', 'label' => 'Retornos', 'type' => 'int'],
                ['key' => 'executions', 'label' => 'Entregues no período', 'type' => 'int'],
                ['key' => 'rate', 'label' => 'Taxa de retorno', 'type' => 'percent'],
                ['key' => 'mechanics', 'label' => 'Quem fez o serviço', 'type' => 'text'],
            ],
            'rows' => $rows->all(),
            'totals' => ['name' => 'Total', 'returns' => (int) $rows->sum('returns'), 'executions' => null, 'rate' => null, 'mechanics' => null],
            'summary' => [
                'returns' => $orders,
                'delivered' => $delivered,
                'return_rate' => $delivered ? round($orders / $delivered * 100, 1) : 0.0,
            ],
        ];
    }

    /** Avaliações respondidas no período, com o NPS. */
    private function satisfaction(Carbon $start, Carbon $end): array
    {
        $orders = ServiceOrder::query()
            ->whereNotNull('survey_answered_at')
            ->whereBetween('survey_answered_at', [$start->copy()->utc(), $end->copy()->utc()])
            ->with(['customer', 'vehicle'])
            ->orderBy('survey_score')
            ->orderByDesc('survey_answered_at')
            ->get();

        return [
            'columns' => [
                ['key' => 'answered_at', 'label' => 'Data', 'type' => 'date'],
                ['key' => 'number', 'label' => 'OS', 'type' => 'text'],
                ['key' => 'customer', 'label' => 'Cliente', 'type' => 'text'],
                ['key' => 'vehicle', 'label' => 'Veículo', 'type' => 'text'],
                ['key' => 'score', 'label' => 'Nota', 'type' => 'int'],
                ['key' => 'comment', 'label' => 'Comentário', 'type' => 'text'],
            ],
            // Piores notas primeiro: é o que precisa de atenção
            'rows' => $orders->map(fn (ServiceOrder $order) => [
                'answered_at' => $order->survey_answered_at->copy()->timezone($this->timezone)->toDateString(),
                'number' => $order->number(),
                'order_id' => $order->id,
                'customer' => $order->customer->trade_name ?: $order->customer->name,
                'vehicle' => trim($order->vehicle->brand.' '.$order->vehicle->model),
                'score' => $order->survey_score,
                'category' => Nps::category($order->survey_score),
                'comment' => $order->survey_comment,
            ])->all(),
            'totals' => null,
            'summary' => Nps::summarize($orders->pluck('survey_score')),
        ];
    }

    /** Mesmo serviço do catálogo (ou mesmo nome, se foi excluído do catálogo). */
    private function serviceKey(ServiceOrderItem $item): string
    {
        return $item->labor_service_id ? 'id:'.$item->labor_service_id : 'name:'.mb_strtolower($item->name);
    }

    private function mechanics(Carbon $start, Carbon $end): array
    {
        $items = ServiceOrderItem::query()
            ->where('is_done', true)
            ->whereBetween('done_at', [$start->copy()->utc(), $end->copy()->utc()])
            ->whereHas('serviceOrder', fn ($query) => $query->where('status', '!=', ServiceOrderStatus::Canceled->value))
            ->get();

        $names = User::whereIn('id', $items->pluck('mechanic_id')->filter()->unique())->pluck('name', 'id');

        $rows = $items->groupBy(fn (ServiceOrderItem $item) => $item->mechanic_id ?? 0)
            ->map(fn (Collection $items, int $mechanicId) => [
                'name' => $mechanicId ? ($names[$mechanicId] ?? 'Usuário removido') : 'Sem responsável',
                'services' => $items->count(),
                'orders' => $items->pluck('service_order_id')->unique()->count(),
                'labor_cents' => (int) $items->sum('price_cents'),
                'unassigned' => $mechanicId === 0,
            ])
            // "Sem responsável" sempre por último
            ->sortBy([['unassigned', 'asc'], ['services', 'desc']])
            ->values();

        return [
            'columns' => [
                ['key' => 'name', 'label' => 'Mecânico', 'type' => 'text'],
                ['key' => 'services', 'label' => 'Serviços feitos', 'type' => 'int'],
                ['key' => 'orders', 'label' => 'OS atendidas', 'type' => 'int'],
                ['key' => 'labor_cents', 'label' => 'Mão de obra', 'type' => 'money'],
            ],
            'rows' => $rows->map(fn (array $row) => collect($row)->except('unassigned')->all())->all(),
            'totals' => [
                'name' => 'Total',
                'services' => (int) $rows->sum('services'),
                'orders' => $items->pluck('service_order_id')->unique()->count(),
                'labor_cents' => (int) $rows->sum('labor_cents'),
            ],
            'summary' => ['mechanics' => $rows->where('unassigned', false)->count()],
        ];
    }
}
