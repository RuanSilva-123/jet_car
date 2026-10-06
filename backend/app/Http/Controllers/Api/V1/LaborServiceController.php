<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ServiceCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\LaborServices\SaveLaborServiceRequest;
use App\Http\Resources\LaborServiceResource;
use App\Models\LaborService;
use App\Support\SuggestedLaborServices;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Catálogo de mão de obra (serviços que a oficina realiza).
 */
class LaborServiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', LaborService::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::enum(ServiceCategory::class)],
            'status' => ['nullable', 'in:active,inactive'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $services = LaborService::query()
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $term = '%'.mb_strtolower(trim($search)).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('lower(name) like ?', [$term])
                    ->orWhereRaw('lower(description) like ?', [$term]));
            })
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return LaborServiceResource::collection($services);
    }

    public function store(SaveLaborServiceRequest $request): LaborServiceResource
    {
        $service = new LaborService($request->validated());
        $service->created_by = $request->user()->id;
        $service->save();

        return new LaborServiceResource($service);
    }

    public function show(LaborService $laborService): LaborServiceResource
    {
        Gate::authorize('view', $laborService);

        return new LaborServiceResource($laborService);
    }

    public function update(SaveLaborServiceRequest $request, LaborService $laborService): LaborServiceResource
    {
        $laborService->update($request->validated());

        return new LaborServiceResource($laborService);
    }

    public function destroy(LaborService $laborService): Response
    {
        Gate::authorize('delete', $laborService);

        // Exclusão lógica: preserva o histórico para as futuras ordens de serviço
        $laborService->delete();

        return response()->noContent();
    }

    /**
     * Adiciona a lista sugerida de serviços comuns, pulando nomes que já existem.
     */
    public function importSuggestions(Request $request): JsonResponse
    {
        Gate::authorize('create', LaborService::class);

        $created = DB::transaction(function () use ($request) {
            $created = 0;

            foreach (SuggestedLaborServices::all() as $suggestion) {
                if (LaborService::nameTaken($suggestion['name'])) {
                    continue;
                }

                $service = new LaborService([...$suggestion, 'is_active' => true]);
                $service->created_by = $request->user()->id;
                $service->save();
                $created++;
            }

            return $created;
        });

        return response()->json(['data' => ['created' => $created]], $created > 0 ? 201 : 200);
    }
}
