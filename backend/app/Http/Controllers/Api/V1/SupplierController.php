<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Support\BrazilianDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Fornecedores (financeiro). Excluir é só para o master; as contas antigas continuam com o nome. */
class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage-finance');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $suppliers = Supplier::query()
            ->withCount(['bills as open_bills_count' => fn (Builder $query) => $query->whereNull('paid_at')])
            ->withSum(['bills as open_bills_cents' => fn (Builder $query) => $query->whereNull('paid_at')], 'amount_cents')
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $term = '%'.mb_strtolower(trim($search)).'%';
                $digits = preg_replace('/\D/', '', $search);
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('lower(name) like ?', [$term])
                    ->orWhereRaw('lower(contact_name) like ?', [$term])
                    ->when(strlen($digits) >= 4, fn (Builder $query) => $query->orWhere('document', 'like', "%{$digits}%")->orWhere('phone', 'like', "%{$digits}%")));
            })
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'data' => $suppliers->getCollection()->map(fn (Supplier $supplier) => $this->present($supplier)),
            'meta' => [
                'current_page' => $suppliers->currentPage(),
                'last_page' => $suppliers->lastPage(),
                'per_page' => $suppliers->perPage(),
                'total' => $suppliers->total(),
                'from' => $suppliers->firstItem(),
                'to' => $suppliers->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-finance');

        $supplier = new Supplier($this->validated($request));
        $supplier->created_by = $request->user()->id;
        $supplier->save();

        return response()->json(['data' => $this->present($supplier)], 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        Gate::authorize('manage-finance');
        $supplier->update($this->validated($request, $supplier));

        return response()->json(['data' => $this->present($supplier)]);
    }

    public function destroy(Request $request, Supplier $supplier): Response
    {
        abort_unless($request->user()->isMaster(), 403, 'Somente o administrador master pode excluir fornecedores.');
        $supplier->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $text = fn (mixed $value) => ($value = trim((string) $value)) === '' ? null : $value;
        $request->merge([
            'name' => preg_replace('/\s+/', ' ', trim((string) $request->input('name'))),
            'document' => ($doc = BrazilianDocument::normalize((string) $request->input('document'))) === '' ? null : $doc,
            'phone' => ($phone = preg_replace('/\D/', '', (string) $request->input('phone'))) === '' ? null : $phone,
            'email' => ($email = $text($request->input('email'))) === null ? null : mb_strtolower($email),
            'contact_name' => $text($request->input('contact_name')),
            'notes' => $text($request->input('notes')),
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'document' => ['nullable', 'string', 'max:14', Rule::unique('suppliers', 'document')->ignore($supplier?->id)->whereNull('deleted_at')],
            'phone' => ['nullable', 'digits_between:10,11'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'name.required' => 'Informe o nome do fornecedor.',
            'document.unique' => 'Já existe um fornecedor com este CPF/CNPJ.',
            'phone.digits_between' => 'Telefone com DDD.',
            'email.email' => 'E-mail inválido.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'document' => $supplier->document,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'contact_name' => $supplier->contact_name,
            'notes' => $supplier->notes,
            'open_bills_count' => (int) ($supplier->open_bills_count ?? 0),
            'open_bills_cents' => (int) ($supplier->open_bills_cents ?? 0),
        ];
    }
}
