<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Parts\SavePartRequest;
use App\Http\Resources\PartResource;
use App\Models\Part;
use App\Models\StockMovement;
use App\Services\Inventory\StockManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Estoque de peças: catálogo, entradas, ajustes de inventário e histórico de movimentação. */
class PartController extends Controller
{
    public function __construct(private readonly StockManager $stock) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Part::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            // low = estoque baixo (no mínimo ou negativo)
            'status' => ['nullable', 'in:active,inactive,low'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $parts = Part::query()
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $term = '%'.mb_strtolower(trim($search)).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('lower(name) like ?', [$term])
                    ->orWhereRaw('lower(part_number) like ?', [$term])
                    ->orWhereRaw('lower(brand) like ?', [$term]));
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => match ($status) {
                'low' => $query->lowStock(),
                default => $query->where('is_active', $status === 'active'),
            })
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return PartResource::collection($parts)->additional([
            'summary' => ['low_stock' => Part::lowStock()->count()],
        ]);
    }

    public function store(SavePartRequest $request): JsonResponse
    {
        $data = $request->validated();

        $part = DB::transaction(function () use ($data, $request) {
            $part = new Part($data);
            $part->created_by = $request->user()->id;
            $part->save();

            if (($data['initial_stock'] ?? 0) > 0) {
                $this->stock->entry($part, (float) $data['initial_stock'], $part->cost_cents, 'Estoque inicial', $request->user());
            }

            return $part;
        });

        return (new PartResource($part->fresh()))->response()->setStatusCode(201);
    }

    public function show(Part $part): PartResource
    {
        Gate::authorize('view', $part);

        return new PartResource($part);
    }

    public function update(SavePartRequest $request, Part $part): PartResource
    {
        $part->update($request->validated());

        return new PartResource($part);
    }

    public function destroy(Part $part): Response
    {
        Gate::authorize('delete', $part);

        // Exclusão lógica: OS antigas continuam apontando para a peça
        $part->delete();

        return response()->noContent();
    }

    /** Entrada de mercadoria (compra) ou ajuste de inventário (contagem). */
    public function moveStock(Request $request, Part $part): PartResource
    {
        Gate::authorize('update', $part);

        $request->merge(['notes' => trim((string) $request->input('notes')) ?: null]);
        $data = $request->validate([
            'type' => ['required', 'in:entry,adjustment'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:999999'],
            'unit_cost_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'type.*' => 'Tipo de movimentação inválido.',
            'quantity.required' => 'Informe a quantidade.',
            'quantity.*' => 'Quantidade inválida.',
            'unit_cost_cents.*' => 'Custo inválido.',
        ]);

        $data['type'] === 'entry'
            ? $this->stock->entry($part, (float) $data['quantity'], $data['unit_cost_cents'] ?? null, $data['notes'], $request->user())
            : $this->stock->adjust($part, (float) $data['quantity'], $data['notes'], $request->user());

        return new PartResource($part->fresh());
    }

    public function movements(Request $request, Part $part): JsonResponse
    {
        Gate::authorize('view', $part);

        $movements = $part->movements()->with(['user', 'serviceOrder'])->paginate(20);

        return response()->json([
            'data' => $movements->getCollection()->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'type' => $movement->type,
                'type_label' => StockMovement::LABELS[$movement->type] ?? $movement->type,
                'quantity' => (float) $movement->quantity,
                'balance_after' => (float) $movement->balance_after,
                'unit_cost_cents' => $movement->unit_cost_cents,
                'notes' => $movement->notes,
                'user' => $movement->user?->name,
                'service_order' => $movement->serviceOrder
                    ? ['id' => $movement->serviceOrder->id, 'number' => $movement->serviceOrder->number()]
                    : null,
                'created_at' => $movement->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
                'from' => $movements->firstItem(),
                'to' => $movements->lastItem(),
            ],
        ]);
    }
}
