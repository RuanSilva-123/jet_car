<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ExpenseCategory;
use App\Http\Controllers\Controller;
use App\Models\RecurringBill;
use App\Services\Finance\BillManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Despesas fixas (aluguel, internet, contador...). Cada uma gera a conta do mês: na hora do
 * cadastro e, depois, todo dia pelo scheduler (jetcar:recurring-bills), sem duplicar.
 */
class RecurringBillController extends Controller
{
    public function __construct(private readonly BillManager $bills) {}

    public function index(): JsonResponse
    {
        Gate::authorize('manage-finance');

        $items = RecurringBill::query()->with('supplier')->orderByDesc('is_active')->orderBy('day_of_month')->get();

        return response()->json([
            'data' => $items->map(fn (RecurringBill $item) => $this->present($item)),
            'summary' => ['monthly_cents' => (int) $items->where('is_active', true)->sum('amount_cents')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-finance');

        $item = new RecurringBill($this->validated($request));
        $item->created_by = $request->user()->id;
        $item->save();
        $this->bills->generateRecurring(only: $item);

        return response()->json(['data' => $this->present($item->load('supplier'))], 201);
    }

    public function update(Request $request, RecurringBill $recurringBill): JsonResponse
    {
        Gate::authorize('manage-finance');

        $recurringBill->update($this->validated($request));
        $this->bills->generateRecurring(only: $recurringBill);

        return response()->json(['data' => $this->present($recurringBill->load('supplier'))]);
    }

    /** Exclui a despesa fixa; as contas já geradas continuam (as pagas fazem parte do histórico). */
    public function destroy(RecurringBill $recurringBill): Response
    {
        Gate::authorize('manage-finance');
        $recurringBill->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $request->merge([
            'description' => preg_replace('/\s+/', ' ', trim((string) $request->input('description'))),
            'notes' => trim((string) $request->input('notes')) ?: null,
        ]);

        return $request->validate([
            'description' => ['required', 'string', 'max:150'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
            'day_of_month' => ['required', 'integer', 'between:1,31'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'description.required' => 'Descreva a despesa.',
            'category.*' => 'Escolha a categoria.',
            'amount_cents.required' => 'Informe o valor.',
            'amount_cents.min' => 'Informe o valor.',
            'amount_cents.*' => 'Valor inválido.',
            'day_of_month.*' => 'Dia do vencimento: de 1 a 31.',
            'starts_on.*' => 'Informe o início.',
            'ends_on.after_or_equal' => 'O fim deve ser depois do início.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(RecurringBill $item): array
    {
        return [
            'id' => $item->id,
            'description' => $item->description,
            'category' => $item->category->value,
            'category_label' => $item->category->label(),
            'amount_cents' => $item->amount_cents,
            'day_of_month' => $item->day_of_month,
            'starts_on' => $item->starts_on->toDateString(),
            'ends_on' => $item->ends_on?->toDateString(),
            'is_active' => $item->is_active,
            'notes' => $item->notes,
            'supplier' => $item->supplier ? ['id' => $item->supplier->id, 'name' => $item->supplier->name] : null,
        ];
    }
}
