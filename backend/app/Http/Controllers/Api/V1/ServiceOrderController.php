<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ServiceOrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceOrders\SaveServiceOrderRequest;
use App\Http\Resources\ServiceOrderResource;
use App\Models\LaborService;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\ServiceOrderPart;
use App\Models\User;
use App\Services\ServiceOrders\ServiceOrderManager;
use App\Support\ShopSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ServiceOrderController extends Controller
{
    public function __construct(private readonly ServiceOrderManager $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ServiceOrder::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            // "active" = todas as que não foram entregues/canceladas
            'status' => ['nullable', Rule::in(['active', ...array_column(ServiceOrderStatus::cases(), 'value')])],
            'customer_id' => ['nullable', 'integer'],
            'vehicle_id' => ['nullable', 'integer'],
            // Serviços atribuídos a um mecânico ("me" = usuário logado)
            'mechanic_id' => ['nullable', 'regex:/^(me|\d+)$/'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $orders = ServiceOrder::query()
            ->with(['customer', 'vehicle'])
            ->withCount(['items', 'items as done_items_count' => fn (Builder $query) => $query->where('is_done', true)])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this->applySearch($query, $search))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $status === 'active'
                ? $query->whereIn('status', ServiceOrderStatus::activeValues())
                : $query->where('status', $status))
            ->when($filters['customer_id'] ?? null, fn (Builder $query, int $id) => $query->where('customer_id', $id))
            ->when($filters['vehicle_id'] ?? null, fn (Builder $query, int $id) => $query->where('vehicle_id', $id))
            ->when($filters['mechanic_id'] ?? null, function (Builder $query, string $mechanic) use ($request) {
                $id = $mechanic === 'me' ? $request->user()->id : (int) $mechanic;
                $query->whereHas('items', fn (Builder $items) => $items->where('mechanic_id', $id));
            })
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return ServiceOrderResource::collection($orders);
    }

    public function store(SaveServiceOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create($request->validated(), $request->user());

        // detail() recarrega o model (fresh), que perde o "recém-criado": status 201 explícito
        return $this->detail($order)->response()->setStatusCode(201);
    }

    public function show(ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('view', $serviceOrder);

        return $this->detail($serviceOrder);
    }

    public function update(SaveServiceOrderRequest $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        $this->orders->update($serviceOrder, $request->validated(), $request->user());

        return $this->detail($serviceOrder);
    }

    public function changeStatus(Request $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('update', $serviceOrder);

        $data = $request->validate([
            'status' => ['required', Rule::enum(ServiceOrderStatus::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['status.required' => 'Selecione o novo status.']);

        $note = trim((string) ($data['note'] ?? '')) ?: null;
        $this->orders->changeStatus($serviceOrder, ServiceOrderStatus::from($data['status']), $note, $request->user());

        return $this->detail($serviceOrder);
    }

    public function sendBudget(Request $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('update', $serviceOrder);
        $this->orders->sendBudget($serviceOrder, $request->user());

        return $this->detail($serviceOrder);
    }

    public function approveBudget(Request $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('update', $serviceOrder);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $this->orders->approveBudget($serviceOrder, trim((string) ($data['note'] ?? '')) ?: null, $request->user());

        return $this->detail($serviceOrder);
    }

    public function rejectBudget(Request $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('update', $serviceOrder);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            // true: cancela a OS; false: volta para revisão do orçamento
            'cancel' => ['required', 'boolean'],
        ]);
        $this->orders->rejectBudget($serviceOrder, trim((string) ($data['note'] ?? '')) ?: null, (bool) $data['cancel'], $request->user());

        return $this->detail($serviceOrder);
    }

    public function toggleItem(Request $request, ServiceOrder $serviceOrder, ServiceOrderItem $item): ServiceOrderResource
    {
        Gate::authorize('update', $serviceOrder);
        abort_unless($item->service_order_id === $serviceOrder->id, 404);

        $data = $request->validate(['is_done' => ['required', 'boolean']]);
        $this->orders->setItemDone($serviceOrder, $item, (bool) $data['is_done'], $request->user());

        return $this->detail($serviceOrder);
    }

    /** OS do mesmo veículo ainda na garantia, com os serviços feitos (para marcar o retorno). */
    public function warrantyCandidates(ServiceOrder $serviceOrder): JsonResponse
    {
        Gate::authorize('view', $serviceOrder);

        $days = (int) ShopSettings::get()['warranty_days'];

        return response()->json([
            'data' => $this->orders->warrantyCandidates($serviceOrder)->map(fn (ServiceOrder $order) => [
                'id' => $order->id,
                'number' => $order->number(),
                'delivered_at' => $order->delivered_at?->toIso8601String(),
                'warranty_until' => $order->warrantyUntil()?->toDateString(),
                'items' => $order->items->map(fn (ServiceOrderItem $item) => ['id' => $item->id, 'name' => $item->name]),
            ])->values(),
            'warranty_days' => $days,
        ]);
    }

    public function linkWarranty(Request $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('update', $serviceOrder);

        $data = $request->validate([
            'warranty_of_id' => ['required', 'integer', Rule::exists('service_orders', 'id')],
            'item_ids' => ['present', 'array', 'max:50'],
            'item_ids.*' => ['integer'],
        ], [
            'warranty_of_id.required' => 'Escolha a OS original.',
            'warranty_of_id.exists' => 'OS original não encontrada.',
        ]);

        $this->orders->linkWarranty($serviceOrder, ServiceOrder::findOrFail($data['warranty_of_id']), array_map('intval', $data['item_ids']), $request->user());

        return $this->detail($serviceOrder);
    }

    public function unlinkWarranty(Request $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('update', $serviceOrder);
        $this->orders->unlinkWarranty($serviceOrder, $request->user());

        return $this->detail($serviceOrder);
    }

    /** Mecânico responsável pelo serviço (null = sem responsável). */
    public function assignMechanic(Request $request, ServiceOrder $serviceOrder, ServiceOrderItem $item): ServiceOrderResource
    {
        Gate::authorize('update', $serviceOrder);
        abort_unless($item->service_order_id === $serviceOrder->id, 404);

        $data = $request->validate([
            'mechanic_id' => [
                'present', 'nullable', 'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Mechanic->value)->where('is_active', true),
            ],
        ], ['mechanic_id.exists' => 'Selecione um mecânico ativo.']);

        $mechanic = isset($data['mechanic_id']) ? User::findOrFail($data['mechanic_id']) : null;
        $this->orders->assignMechanic($serviceOrder, $item, $mechanic, $request->user());

        return $this->detail($serviceOrder);
    }

    /** Diagnóstico: adiciona um serviço do catálogo (sem valor). */
    public function addItem(Request $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('update', $serviceOrder);

        $data = $request->validate([
            'labor_service_id' => ['required', 'integer', Rule::exists('labor_services', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'labor_service_id.required' => 'Selecione o serviço.',
            'labor_service_id.exists' => 'Serviço inexistente ou inativo no catálogo.',
        ]);

        $this->orders->addItem(
            $serviceOrder,
            LaborService::findOrFail($data['labor_service_id']),
            trim((string) ($data['notes'] ?? '')) ?: null,
            $request->user(),
        );

        return $this->detail($serviceOrder);
    }

    public function removeItem(Request $request, ServiceOrder $serviceOrder, ServiceOrderItem $item): ServiceOrderResource
    {
        Gate::authorize('update', $serviceOrder);
        abort_unless($item->service_order_id === $serviceOrder->id, 404);

        $this->orders->removeItem($serviceOrder, $item, $request->user());

        return $this->detail($serviceOrder);
    }

    /** Diagnóstico: adiciona uma peça necessária (valor opcional; normalmente definido no orçamento). */
    public function addPart(Request $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('update', $serviceOrder);

        $request->merge([
            'name' => trim((string) $request->input('name')) ?: null,
            'part_number' => trim((string) $request->input('part_number')) ?: null,
        ]);
        $data = $request->validate([
            // Do estoque: nome/código/preço vêm do catálogo e a quantidade sai do estoque
            'part_id' => ['nullable', 'integer', Rule::exists('parts', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'name' => ['required_without:part_id', 'nullable', 'string', 'max:150'],
            'part_number' => ['nullable', 'string', 'max:60'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'unit_price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ], [
            'name.required_without' => 'Informe o nome da peça.',
            'part_id.exists' => 'Peça inexistente ou inativa no estoque.',
            'quantity.required' => 'Informe a quantidade.',
            'quantity.gt' => 'Quantidade deve ser maior que zero.',
            'quantity.*' => 'Quantidade inválida.',
            'unit_price_cents.*' => 'Valor da peça inválido.',
        ]);

        $this->orders->addPart($serviceOrder, $data, $request->user());

        return $this->detail($serviceOrder);
    }

    /** Altera valor unitário e/ou quantidade de uma peça da OS (também as do estoque). */
    public function updatePart(Request $request, ServiceOrder $serviceOrder, ServiceOrderPart $part): ServiceOrderResource
    {
        Gate::authorize('update', $serviceOrder);
        abort_unless($part->service_order_id === $serviceOrder->id, 404);

        $data = $request->validate([
            'quantity' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:99999'],
            'unit_price_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
        ], [
            'quantity.required' => 'Informe a quantidade.',
            'quantity.gt' => 'Quantidade deve ser maior que zero.',
            'quantity.*' => 'Quantidade inválida.',
            'unit_price_cents.*' => 'Valor da peça inválido.',
        ]);

        $this->orders->updatePart($serviceOrder, $part, $data, $request->user());

        return $this->detail($serviceOrder);
    }

    public function removePart(Request $request, ServiceOrder $serviceOrder, ServiceOrderPart $part): ServiceOrderResource
    {
        Gate::authorize('update', $serviceOrder);
        abort_unless($part->service_order_id === $serviceOrder->id, 404);

        $this->orders->removePart($serviceOrder, $part, $request->user());

        return $this->detail($serviceOrder);
    }

    public function addNote(Request $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('update', $serviceOrder);

        $data = $request->validate(
            ['note' => ['required', 'string', 'max:2000']],
            ['note.required' => 'Escreva o comentário.'],
        );
        $this->orders->addNote($serviceOrder, trim($data['note']), $request->user());

        return $this->detail($serviceOrder);
    }

    private function detail(ServiceOrder $order): ServiceOrderResource
    {
        return new ServiceOrderResource($order->fresh()->load(ServiceOrderResource::DETAIL_RELATIONS));
    }

    /** Busca por número da OS, cliente, placa ou modelo do veículo. */
    private function applySearch(Builder $query, string $search): void
    {
        $term = '%'.mb_strtolower(trim($search)).'%';
        $number = ltrim(preg_replace('/\D/', '', $search), '0');
        $plate = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($search));

        $query->where(function (Builder $query) use ($term, $number, $plate) {
            if ($number !== '' && strlen($number) <= 9) {
                $query->orWhere('id', (int) $number);
            }

            $query->orWhereHas('customer', fn (Builder $customer) => $customer->withTrashed()->where(fn (Builder $customer) => $customer
                ->whereRaw('lower(name) like ?', [$term])
                ->orWhereRaw('lower(trade_name) like ?', [$term])));

            $query->orWhereHas('vehicle', fn (Builder $vehicle) => $vehicle->withTrashed()->where(function (Builder $vehicle) use ($term, $plate) {
                $vehicle->whereRaw('lower(model) like ?', [$term]);
                if (strlen($plate) >= 3) {
                    $vehicle->orWhere('plate', 'like', "%{$plate}%");
                }
            }));
        });
    }
}
