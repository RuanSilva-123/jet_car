<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Services\Finance\BillManager;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Contas a pagar. Filtros: status (open, overdue, paid, all), mês do vencimento, fornecedor,
 * categoria e busca. Estornar um pagamento é só para o master.
 */
class BillController extends Controller
{
    public function __construct(private readonly BillManager $bills) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage-finance');

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'overdue', 'paid', 'all'])],
            'month' => ['nullable', 'date_format:Y-m'],
            'supplier_id' => ['nullable', 'integer'],
            'category' => ['nullable', Rule::enum(ExpenseCategory::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $today = LocalTime::now()->toDateString();
        $status = $filters['status'] ?? 'open';

        $query = Bill::query()
            ->with(['supplier', 'payer'])
            ->when($status === 'open', fn (Builder $query) => $query->whereNull('paid_at'))
            ->when($status === 'overdue', fn (Builder $query) => $query->whereNull('paid_at')->whereDate('due_date', '<', $today))
            ->when($status === 'paid', fn (Builder $query) => $query->whereNotNull('paid_at'))
            ->when($filters['month'] ?? null, function (Builder $query, string $month) use ($status) {
                $start = Carbon::parse($month.'-01');
                // Pagas: pelo mês do pagamento; as demais, pelo vencimento
                $column = $status === 'paid' ? 'paid_at' : 'due_date';
                $query->whereDate($column, '>=', $start->toDateString())->whereDate($column, '<=', $start->copy()->endOfMonth()->toDateString());
            })
            ->when($filters['supplier_id'] ?? null, fn (Builder $query, int $id) => $query->where('supplier_id', $id))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $term = '%'.mb_strtolower(trim($search)).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('lower(description) like ?', [$term])
                    ->orWhereRaw('lower(document_number) like ?', [$term])
                    ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->withTrashed()->whereRaw('lower(name) like ?', [$term])));
            });

        $bills = (clone $query)
            ->orderBy($status === 'paid' ? 'paid_at' : 'due_date', $status === 'paid' ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $open = Bill::query()->open();
        $monthStart = LocalTime::now()->startOfMonth()->toDateString();
        $monthEnd = LocalTime::now()->endOfMonth()->toDateString();

        return response()->json([
            'data' => $bills->getCollection()->map(fn (Bill $bill) => $this->present($bill)),
            'meta' => [
                'current_page' => $bills->currentPage(),
                'last_page' => $bills->lastPage(),
                'per_page' => $bills->perPage(),
                'total' => $bills->total(),
                'from' => $bills->firstItem(),
                'to' => $bills->lastItem(),
            ],
            'summary' => [
                'filtered_cents' => (int) (clone $query)->sum('amount_cents'),
                'overdue' => [
                    'count' => (clone $open)->whereDate('due_date', '<', $today)->count(),
                    'cents' => (int) (clone $open)->whereDate('due_date', '<', $today)->sum('amount_cents'),
                ],
                'due_today' => [
                    'count' => (clone $open)->whereDate('due_date', $today)->count(),
                    'cents' => (int) (clone $open)->whereDate('due_date', $today)->sum('amount_cents'),
                ],
                'open_this_month' => [
                    'count' => (clone $open)->whereDate('due_date', '>=', $monthStart)->whereDate('due_date', '<=', $monthEnd)->count(),
                    'cents' => (int) (clone $open)->whereDate('due_date', '>=', $monthStart)->whereDate('due_date', '<=', $monthEnd)->sum('amount_cents'),
                ],
                'paid_this_month' => (int) Bill::query()->whereDate('paid_at', '>=', $monthStart)->whereDate('paid_at', '<=', $monthEnd)->sum('amount_cents'),
            ],
            'options' => ['categories' => ExpenseCategory::options()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-finance');

        $data = $this->validated($request, creating: true);
        $bills = $this->bills->create($data, $request->user());

        return response()->json(['data' => $bills->map(fn (Bill $bill) => $this->present($bill->fresh(['supplier', 'payer'])))], 201);
    }

    public function update(Request $request, Bill $bill): JsonResponse
    {
        Gate::authorize('manage-finance');

        $data = $this->validated($request, creating: false);
        if ($bill->isPaid() && ((int) $data['amount_cents'] !== $bill->amount_cents || $data['due_date'] !== $bill->due_date->toDateString())) {
            throw ValidationException::withMessages(['amount_cents' => 'Conta já paga: estorne o pagamento para mudar valor ou vencimento.']);
        }

        $bill->update($data);

        return response()->json(['data' => $this->present($bill->fresh(['supplier', 'payer']))]);
    }

    public function destroy(Bill $bill): Response
    {
        Gate::authorize('manage-finance');
        if ($bill->isPaid()) {
            throw ValidationException::withMessages(['bill' => 'Conta já paga: estorne o pagamento antes de excluir.']);
        }
        $bill->delete();

        return response()->noContent();
    }

    public function pay(Request $request, Bill $bill): JsonResponse
    {
        Gate::authorize('manage-finance');

        $data = $request->validate([
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            // Valor efetivamente pago (juros, multa ou desconto); vazio = valor da conta
            'amount_cents' => ['nullable', 'integer', 'min:1', 'max:100000000'],
        ], [
            'paid_at.required' => 'Informe a data do pagamento.',
            'paid_at.before_or_equal' => 'A data do pagamento não pode ser futura.',
            'payment_method.*' => 'Forma de pagamento inválida.',
            'amount_cents.*' => 'Valor pago inválido.',
        ]);

        $this->bills->pay($bill, $data['paid_at'], $data['payment_method'], $data['amount_cents'] ?? null, $request->user());

        return response()->json(['data' => $this->present($bill->fresh(['supplier', 'payer']))]);
    }

    public function unpay(Request $request, Bill $bill): JsonResponse
    {
        abort_unless($request->user()->isMaster(), 403, 'Somente o administrador master pode estornar pagamentos.');
        $this->bills->unpay($bill);

        return response()->json(['data' => $this->present($bill->fresh(['supplier', 'payer']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $text = fn (mixed $value) => ($value = trim((string) $value)) === '' ? null : $value;
        $request->merge([
            'description' => preg_replace('/\s+/', ' ', trim((string) $request->input('description'))),
            'document_number' => $text($request->input('document_number')),
            'notes' => $text($request->input('notes')),
        ]);

        return $request->validate([
            'description' => ['required', 'string', 'max:150'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'document_number' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...($creating ? [
                'installments' => ['nullable', 'integer', 'min:1', 'max:48'],
                // Já paga no lançamento (só à vista)
                'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
                'payment_method' => ['nullable', 'required_with:paid_at', Rule::enum(PaymentMethod::class)],
            ] : []),
        ], [
            'description.required' => 'Descreva a conta.',
            'supplier_id.exists' => 'Fornecedor não encontrado.',
            'category.*' => 'Escolha a categoria.',
            'amount_cents.required' => 'Informe o valor.',
            'amount_cents.min' => 'Informe o valor.',
            'amount_cents.*' => 'Valor inválido.',
            'due_date.*' => 'Informe o vencimento.',
            'installments.*' => 'Parcelas: de 1 a 48.',
            'paid_at.before_or_equal' => 'A data do pagamento não pode ser futura.',
            'payment_method.required_with' => 'Informe a forma de pagamento.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Bill $bill): array
    {
        return [
            'id' => $bill->id,
            'description' => $bill->description,
            'category' => $bill->category->value,
            'category_label' => $bill->category->label(),
            'amount_cents' => $bill->amount_cents,
            'due_date' => $bill->due_date->toDateString(),
            'paid_at' => $bill->paid_at?->toDateString(),
            'payment_method' => $bill->payment_method?->value,
            'payment_method_label' => $bill->payment_method?->label(),
            'paid_by' => $bill->payer?->name,
            'is_overdue' => ! $bill->isPaid() && $bill->due_date->toDateString() < LocalTime::now()->toDateString(),
            'document_number' => $bill->document_number,
            'notes' => $bill->notes,
            'installment_number' => $bill->installment_number,
            'installment_count' => $bill->installment_count,
            'recurring_bill_id' => $bill->recurring_bill_id,
            'supplier' => $bill->supplier ? ['id' => $bill->supplier->id, 'name' => $bill->supplier->name] : null,
        ];
    }
}
