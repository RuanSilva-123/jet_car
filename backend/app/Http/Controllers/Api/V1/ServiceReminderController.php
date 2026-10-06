<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReminderStatus;
use App\Http\Controllers\Controller;
use App\Models\ServiceReminder;
use App\Services\Reminders\ServiceReminderGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Lista de clientes a contatar para revisão (gerada pelo scheduler) e o retorno de cada contato. */
class ServiceReminderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            // open = a contatar + contatados (lista de trabalho)
            'status' => ['nullable', Rule::in(['open', 'all', ...array_column(ReminderStatus::cases(), 'value')])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $status = $filters['status'] ?? 'open';

        $reminders = ServiceReminder::query()
            ->with(['vehicle', 'customer', 'contactedBy'])
            ->when($status !== 'all', fn (Builder $query) => $status === 'open'
                ? $query->whereIn('status', ReminderStatus::openValues())
                : $query->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $term = '%'.mb_strtolower(trim($search)).'%';
                $plate = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($search));
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('lower(service_name) like ?', [$term])
                    ->orWhereHas('customer', fn (Builder $customer) => $customer->withTrashed()->whereRaw('lower(name) like ?', [$term]))
                    ->when(strlen($plate) >= 3, fn (Builder $query) => $query->orWhereHas('vehicle', fn (Builder $vehicle) => $vehicle->withTrashed()->where('plate', 'like', "%{$plate}%"))));
            })
            ->orderByRaw('case when due_at is null then 1 else 0 end')
            ->orderBy('due_at')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'data' => $reminders->getCollection()->map(fn (ServiceReminder $reminder) => $this->present($reminder)),
            'meta' => [
                'current_page' => $reminders->currentPage(),
                'last_page' => $reminders->lastPage(),
                'per_page' => $reminders->perPage(),
                'total' => $reminders->total(),
                'from' => $reminders->firstItem(),
                'to' => $reminders->lastItem(),
            ],
            'summary' => [
                'pending' => ServiceReminder::where('status', ReminderStatus::Pending->value)->count(),
                'contacted' => ServiceReminder::where('status', ReminderStatus::Contacted->value)->count(),
            ],
        ]);
    }

    /** Retorno do contato: contatado, agendado, descartado (ou de volta para a lista). */
    public function update(Request $request, ServiceReminder $serviceReminder): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([
                ReminderStatus::Pending->value, ReminderStatus::Contacted->value,
                ReminderStatus::Scheduled->value, ReminderStatus::Dismissed->value,
            ])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['status.*' => 'Situação inválida.']);

        $serviceReminder->status = ReminderStatus::from($data['status']);
        if (array_key_exists('notes', $data)) {
            $serviceReminder->notes = trim((string) $data['notes']) ?: null;
        }
        if ($serviceReminder->status !== ReminderStatus::Pending && $serviceReminder->contacted_at === null) {
            $serviceReminder->contacted_at = now();
            $serviceReminder->contacted_by = $request->user()->id;
        }
        $serviceReminder->save();

        return response()->json(['data' => $this->present($serviceReminder->load(['vehicle', 'customer', 'contactedBy']))]);
    }

    /** Atualiza a lista agora (o scheduler faz isso todo dia às 6h). */
    public function refresh(ServiceReminderGenerator $generator): JsonResponse
    {
        return response()->json(['data' => $generator->run()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ServiceReminder $reminder): array
    {
        return [
            'id' => $reminder->id,
            'service_name' => $reminder->service_name,
            'labor_service_id' => $reminder->labor_service_id,
            'status' => $reminder->status->value,
            'status_label' => $reminder->status->label(),
            'last_done_at' => $reminder->last_done_at->toDateString(),
            'last_mileage' => $reminder->last_mileage,
            'due_at' => $reminder->due_at?->toDateString(),
            'due_mileage' => $reminder->due_mileage,
            'is_overdue' => $reminder->isOverdue(),
            'notes' => $reminder->notes,
            'contacted_at' => $reminder->contacted_at?->toIso8601String(),
            'contacted_by' => $reminder->contactedBy?->name,
            'customer' => [
                'id' => $reminder->customer->id,
                'name' => $reminder->customer->trade_name ?: $reminder->customer->name,
                'phone' => $reminder->customer->phone,
                'phone_is_whatsapp' => $reminder->customer->phone_is_whatsapp,
            ],
            'vehicle' => [
                'id' => $reminder->vehicle->id,
                'brand' => $reminder->vehicle->brand,
                'model' => $reminder->vehicle->model,
                'plate' => $reminder->vehicle->plate,
                'mileage' => $reminder->vehicle->mileage,
            ],
        ];
    }
}
