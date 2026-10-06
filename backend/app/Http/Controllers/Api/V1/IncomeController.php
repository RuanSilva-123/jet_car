<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IncomeCategory;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Income;
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
 * Outras entradas do caixa (não vêm de OS): aporte do dono, empréstimo, venda de um bem...
 * Prevista (`expected_on`) ou já recebida (`received_at`). Estornar ou excluir uma entrada
 * recebida é só para o master, porque mexe no saldo.
 */
class IncomeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage-finance');

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'received', 'all'])],
            'month' => ['nullable', 'date_format:Y-m'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $status = $filters['status'] ?? 'all';
        $today = LocalTime::now()->toDateString();

        $query = Income::query()
            ->with('receiver')
            ->when($status === 'pending', fn (Builder $query) => $query->whereNull('received_at'))
            ->when($status === 'received', fn (Builder $query) => $query->whereNotNull('received_at'))
            ->when($filters['month'] ?? null, function (Builder $query, string $month) use ($status) {
                $start = Carbon::parse($month.'-01');
                // Recebidas: pelo dia em que entrou; as demais, pela data prevista
                $column = $status === 'received' ? 'received_at' : 'expected_on';
                $query->whereDate($column, '>=', $start->toDateString())->whereDate($column, '<=', $start->copy()->endOfMonth()->toDateString());
            })
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query
                ->whereRaw('lower(description) like ?', ['%'.mb_strtolower(trim($search)).'%']));

        $incomes = (clone $query)
            // Pendentes primeiro (pela data prevista), depois as recebidas mais recentes
            ->orderByRaw('case when received_at is null then 0 else 1 end')
            ->orderByRaw('case when received_at is null then expected_on end asc')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $pending = Income::query()->pending();
        $monthStart = LocalTime::now()->startOfMonth()->toDateString();

        return response()->json([
            'data' => $incomes->getCollection()->map(fn (Income $income) => $this->present($income)),
            'meta' => [
                'current_page' => $incomes->currentPage(),
                'last_page' => $incomes->lastPage(),
                'per_page' => $incomes->perPage(),
                'total' => $incomes->total(),
                'from' => $incomes->firstItem(),
                'to' => $incomes->lastItem(),
            ],
            'summary' => [
                'pending' => ['count' => (clone $pending)->count(), 'cents' => (int) (clone $pending)->sum('amount_cents')],
                'late' => [
                    'count' => (clone $pending)->whereDate('expected_on', '<', $today)->count(),
                    'cents' => (int) (clone $pending)->whereDate('expected_on', '<', $today)->sum('amount_cents'),
                ],
                'received_this_month' => (int) Income::query()->whereDate('received_at', '>=', $monthStart)->whereDate('received_at', '<=', $today)->sum('amount_cents'),
            ],
            'options' => ['categories' => IncomeCategory::options()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-finance');

        $data = $this->validated($request, creating: true);
        $income = new Income($data);
        $income->created_by = $request->user()->id;
        if (! empty($data['received_at'])) {
            $income->received_at = $data['received_at'];
            $income->payment_method = $data['payment_method'];
            $income->received_by = $request->user()->id;
            // Já recebida sem data prevista informada: a prevista é o próprio dia
            $income->expected_on ??= $data['received_at'];
        }
        $income->save();

        return response()->json(['data' => $this->present($income->fresh('receiver'))], 201);
    }

    public function update(Request $request, Income $income): JsonResponse
    {
        Gate::authorize('manage-finance');

        $data = $this->validated($request, creating: false);
        if ($income->isReceived() && (int) $data['amount_cents'] !== $income->amount_cents) {
            throw ValidationException::withMessages(['amount_cents' => 'Entrada já recebida: estorne o recebimento para mudar o valor.']);
        }
        $income->update($data);

        return response()->json(['data' => $this->present($income->fresh('receiver'))]);
    }

    public function destroy(Request $request, Income $income): Response
    {
        Gate::authorize('manage-finance');
        abort_if($income->isReceived() && ! $request->user()->isMaster(), 403, 'Somente o administrador master exclui uma entrada já recebida.');

        $income->delete();

        return response()->noContent();
    }

    /** Dinheiro entrou: data, forma e o valor que efetivamente entrou. */
    public function receive(Request $request, Income $income): JsonResponse
    {
        Gate::authorize('manage-finance');
        if ($income->isReceived()) {
            throw ValidationException::withMessages(['income' => 'Esta entrada já foi recebida.']);
        }

        $data = $request->validate([
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount_cents' => ['nullable', 'integer', 'min:1', 'max:100000000'],
        ], [
            'received_at.required' => 'Informe a data em que entrou.',
            'received_at.before_or_equal' => 'A data não pode ser futura.',
            'payment_method.*' => 'Forma de recebimento inválida.',
            'amount_cents.*' => 'Valor inválido.',
        ]);

        $income->forceFill([
            'received_at' => $data['received_at'],
            'payment_method' => $data['payment_method'],
            'amount_cents' => $data['amount_cents'] ?? $income->amount_cents,
            'received_by' => $request->user()->id,
        ])->save();

        return response()->json(['data' => $this->present($income->fresh('receiver'))]);
    }

    public function unreceive(Request $request, Income $income): JsonResponse
    {
        abort_unless($request->user()->isMaster(), 403, 'Somente o administrador master estorna recebimentos.');

        $income->forceFill(['received_at' => null, 'payment_method' => null, 'received_by' => null])->save();

        return response()->json(['data' => $this->present($income->fresh('receiver'))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $request->merge([
            'description' => preg_replace('/\s+/', ' ', trim((string) $request->input('description'))),
            'notes' => trim((string) $request->input('notes')) ?: null,
        ]);

        return $request->validate([
            'description' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::enum(IncomeCategory::class)],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
            // Prevista: obrigatória se ainda não entrou
            'expected_on' => [$creating ? 'required_without:received_at' : 'required', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...($creating ? [
                'received_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
                'payment_method' => ['nullable', 'required_with:received_at', Rule::enum(PaymentMethod::class)],
            ] : []),
        ], [
            'description.required' => 'Descreva a entrada.',
            'category.*' => 'Escolha o tipo da entrada.',
            'amount_cents.required' => 'Informe o valor.',
            'amount_cents.min' => 'Informe o valor.',
            'amount_cents.*' => 'Valor inválido.',
            'expected_on.*' => 'Informe quando o dinheiro deve entrar.',
            'received_at.before_or_equal' => 'A data não pode ser futura.',
            'received_at.*' => 'Data inválida.',
            'payment_method.required_with' => 'Informe a forma de recebimento.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Income $income): array
    {
        return [
            'id' => $income->id,
            'description' => $income->description,
            'category' => $income->category->value,
            'category_label' => $income->category->label(),
            'amount_cents' => $income->amount_cents,
            'expected_on' => $income->expected_on->toDateString(),
            'received_at' => $income->received_at?->toDateString(),
            'payment_method' => $income->payment_method?->value,
            'payment_method_label' => $income->payment_method?->label(),
            'received_by' => $income->receiver?->name,
            'is_late' => ! $income->isReceived() && $income->expected_on->toDateString() < LocalTime::now()->toDateString(),
            'notes' => $income->notes,
        ];
    }
}
