<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Enums\ServiceOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceOrderResource;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Financeiro: contas a receber (OS aprovadas com saldo em aberto) e recebimentos por período.
 */
class FinanceController extends Controller
{
    /**
     * OS com saldo a receber. "delivered" = carro já entregue sem quitar (cobrança);
     * "in_service" = ainda na oficina (vai receber na entrega).
     */
    public function receivables(Request $request): JsonResponse
    {
        Gate::authorize('manage-finance');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', Rule::in(['all', 'delivered', 'in_service'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $base = fn () => ServiceOrder::query()
            ->whereNotNull('budget_approved_at')
            ->where('status', '!=', ServiceOrderStatus::Canceled->value)
            ->whereColumn('total_cents', '>', 'paid_cents');

        $orders = $base()
            ->with(['customer', 'vehicle'])
            ->when($filters['scope'] ?? null, fn (Builder $query, string $scope) => match ($scope) {
                'delivered' => $query->where('status', ServiceOrderStatus::Delivered->value),
                'in_service' => $query->where('status', '!=', ServiceOrderStatus::Delivered->value),
                default => $query,
            })
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this->applySearch($query, $search))
            // Entregues primeiro (cobrança), mais antigas antes
            ->orderByRaw('case when status = ? then 0 else 1 end', [ServiceOrderStatus::Delivered->value])
            ->orderBy('delivered_at')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        $summary = fn (Builder $query) => [
            'count' => (clone $query)->count(),
            'balance_cents' => (int) (clone $query)->sum('total_cents') - (int) (clone $query)->sum('paid_cents'),
        ];

        return ServiceOrderResource::collection($orders)->additional([
            'summary' => [
                'all' => $summary($base()),
                'delivered' => $summary($base()->where('status', ServiceOrderStatus::Delivered->value)),
                'in_service' => $summary($base()->where('status', '!=', ServiceOrderStatus::Delivered->value)),
            ],
        ])->response();
    }

    /** Recebimentos no período (padrão: mês atual), com total por forma de pagamento. */
    public function payments(Request $request): JsonResponse
    {
        Gate::authorize('manage-finance');

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $from = Carbon::parse($filters['from'] ?? now()->startOfMonth())->toDateString();
        $to = Carbon::parse($filters['to'] ?? now())->toDateString();

        $query = ServiceOrderPayment::query()
            ->whereDate('paid_at', '>=', $from)
            ->whereDate('paid_at', '<=', $to)
            ->when($filters['method'] ?? null, fn (Builder $query, string $method) => $query->where('method', $method));

        $byMethod = (clone $query)
            ->selectRaw('method, count(*) as count, sum(amount_cents) as total_cents')
            ->groupBy('method')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->method->value,
                'method_label' => $row->method->label(),
                'count' => (int) $row->count,
                'total_cents' => (int) $row->total_cents,
            ])
            ->sortByDesc('total_cents')
            ->values();

        $payments = $query
            ->with(['serviceOrder.customer', 'serviceOrder.vehicle', 'receiver'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'data' => $payments->getCollection()->map(fn (ServiceOrderPayment $payment) => [
                'id' => $payment->id,
                'method' => $payment->method->value,
                'method_label' => $payment->method->label(),
                'amount_cents' => $payment->amount_cents,
                'installments' => $payment->installments,
                'paid_at' => $payment->paid_at->toDateString(),
                'notes' => $payment->notes,
                'received_by' => $payment->receiver?->name,
                'order' => [
                    'id' => $payment->serviceOrder->id,
                    'number' => $payment->serviceOrder->number(),
                    'customer' => $payment->serviceOrder->customer->trade_name ?: $payment->serviceOrder->customer->name,
                    'vehicle' => trim($payment->serviceOrder->vehicle->brand.' '.$payment->serviceOrder->vehicle->model),
                    'plate' => $payment->serviceOrder->vehicle->plate,
                ],
            ]),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'from' => $payments->firstItem(),
                'to' => $payments->lastItem(),
            ],
            'summary' => [
                'from' => $from,
                'to' => $to,
                'total_cents' => (int) $byMethod->sum('total_cents'),
                'count' => (int) $byMethod->sum('count'),
                'by_method' => $byMethod,
            ],
        ]);
    }

    /** Busca por número da OS, cliente ou placa. */
    private function applySearch(Builder $query, string $search): void
    {
        $term = '%'.mb_strtolower(trim($search)).'%';
        $number = ltrim(preg_replace('/\D/', '', $search), '0');
        $plate = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($search));

        $query->where(function (Builder $query) use ($term, $number, $plate) {
            if ($number !== '' && strlen($number) <= 9) {
                $query->orWhere('id', (int) $number);
            }
            $query->orWhereHas('customer', fn (Builder $customer) => $customer->withTrashed()
                ->where(fn (Builder $customer) => $customer->whereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(trade_name) like ?', [$term])));
            if (strlen($plate) >= 3) {
                $query->orWhereHas('vehicle', fn (Builder $vehicle) => $vehicle->withTrashed()->where('plate', 'like', "%{$plate}%"));
            }
        });
    }
}
