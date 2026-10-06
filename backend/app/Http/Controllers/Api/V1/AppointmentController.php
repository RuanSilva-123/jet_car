<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Enums\ReminderStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ServiceReminder;
use App\Services\ServiceOrders\ServiceOrderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Agenda da oficina. Datas trafegam em ISO 8601 com fuso (o painel converte para o horário local).
 * Check-in: o carro chegou → abre a OS com cliente, veículo e o motivo do agendamento.
 */
class AppointmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'customer_id' => ['nullable', 'integer'],
        ], ['from.required' => 'Informe o período.', 'to.after' => 'Período inválido.']);

        $from = Carbon::parse($filters['from']);
        $to = Carbon::parse($filters['to']);
        if ($from->diffInDays($to) > 62) {
            throw ValidationException::withMessages(['to' => 'Consulte no máximo 62 dias por vez.']);
        }

        $appointments = Appointment::query()
            ->with(['customer', 'vehicle', 'serviceOrder', 'creator'])
            ->where('scheduled_at', '>=', $from->utc())
            ->where('scheduled_at', '<', $to->utc())
            ->when($filters['customer_id'] ?? null, fn ($query, int $id) => $query->where('customer_id', $id))
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $appointments->map(fn (Appointment $appointment) => $this->present($appointment))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $appointment = DB::transaction(function () use ($data, $request) {
            $appointment = new Appointment($data);
            $appointment->status = AppointmentStatus::Scheduled;
            $appointment->created_by = $request->user()->id;
            $appointment->save();

            // Veio da lista de revisão: o lembrete sai da lista de contatos
            if ($reminder = ServiceReminder::find($appointment->service_reminder_id)) {
                $reminder->status = ReminderStatus::Scheduled;
                $reminder->contacted_at ??= now();
                $reminder->contacted_by ??= $request->user()->id;
                $reminder->save();
            }

            return $appointment;
        });

        return response()->json(['data' => $this->present($appointment->load(['customer', 'vehicle', 'creator']))], 201);
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $this->ensureOpen($appointment);
        $appointment->update($this->validated($request, $appointment));

        return response()->json(['data' => $this->present($appointment->load(['customer', 'vehicle', 'creator']))]);
    }

    /** Confirmar, cancelar, marcar falta ou voltar para "agendado". */
    public function changeStatus(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([
                AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value,
                AppointmentStatus::NoShow->value, AppointmentStatus::Canceled->value,
            ])],
        ], ['status.*' => 'Situação inválida.']);

        if ($appointment->status === AppointmentStatus::Arrived) {
            throw ValidationException::withMessages(['status' => 'O carro já chegou: o agendamento virou a OS #'.$appointment->serviceOrder?->number().'.']);
        }

        $appointment->status = AppointmentStatus::from($data['status']);
        $appointment->save();

        return response()->json(['data' => $this->present($appointment->load(['customer', 'vehicle', 'creator']))]);
    }

    /** O carro chegou: abre a OS (entrada) a partir do agendamento. */
    public function checkIn(Request $request, Appointment $appointment, ServiceOrderManager $orders): JsonResponse
    {
        $this->ensureOpen($appointment);

        $data = $request->validate([
            'vehicle_id' => [
                Rule::requiredIf($appointment->vehicle_id === null), 'nullable', 'integer',
                Rule::exists('vehicles', 'id')->where('customer_id', $appointment->customer_id)->whereNull('deleted_at'),
            ],
            'mileage' => ['nullable', 'integer', 'between:0,9999999'],
        ], [
            'vehicle_id.required' => 'Selecione o veículo que chegou.',
            'vehicle_id.exists' => 'Este veículo não pertence ao cliente.',
            'mileage.*' => 'Quilometragem inválida.',
        ]);

        if ($appointment->customer->trashed()) {
            throw ValidationException::withMessages(['customer' => 'O cliente foi removido do cadastro.']);
        }

        $order = DB::transaction(function () use ($appointment, $data, $request, $orders) {
            $order = $orders->create([
                'customer_id' => $appointment->customer_id,
                'vehicle_id' => $data['vehicle_id'] ?? $appointment->vehicle_id,
                'mileage' => $data['mileage'] ?? null,
                'complaint' => $appointment->notes,
            ], $request->user());

            $orders->addNote($order, 'Veículo agendado para '.$appointment->scheduled_at->timezone(config('jetcar.timezone'))->format('d/m/Y \à\s H:i').'.', $request->user());

            $appointment->forceFill([
                'status' => AppointmentStatus::Arrived,
                'service_order_id' => $order->id,
                'vehicle_id' => $order->vehicle_id,
            ])->save();

            return $order;
        });

        return response()->json(['data' => [
            'appointment' => $this->present($appointment->load(['customer', 'vehicle', 'serviceOrder', 'creator'])),
            'service_order_id' => $order->id,
        ]], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Appointment $appointment = null): array
    {
        $request->merge(['notes' => trim((string) $request->input('notes')) ?: null]);
        $customerId = (int) $request->input('customer_id', $appointment?->customer_id);

        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->where('customer_id', $customerId)->whereNull('deleted_at')],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'between:15,600'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'service_reminder_id' => [$appointment ? 'prohibited' : 'nullable', 'integer', Rule::exists('service_reminders', 'id')->where('customer_id', $customerId)],
        ], [
            'customer_id.required' => 'Selecione o cliente.',
            'customer_id.exists' => 'Cliente não encontrado.',
            'vehicle_id.exists' => 'Este veículo não pertence ao cliente.',
            'scheduled_at.required' => 'Informe a data e o horário.',
            'scheduled_at.date' => 'Data inválida.',
            'duration_minutes.*' => 'Duração: de 15 minutos a 10 horas.',
            'service_reminder_id.exists' => 'Lembrete não encontrado para este cliente.',
        ]);

        $data['scheduled_at'] = Carbon::parse($data['scheduled_at'])->utc();

        return $data;
    }

    private function ensureOpen(Appointment $appointment): void
    {
        if (! $appointment->status->isOpen()) {
            throw ValidationException::withMessages(['status' => "Agendamento {$appointment->status->label()}: não pode mais ser alterado."]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Appointment $appointment): array
    {
        return [
            'id' => $appointment->id,
            'scheduled_at' => $appointment->scheduled_at->toIso8601String(),
            'ends_at' => $appointment->scheduled_at->copy()->addMinutes($appointment->duration_minutes)->toIso8601String(),
            'duration_minutes' => $appointment->duration_minutes,
            'notes' => $appointment->notes,
            'status' => $appointment->status->value,
            'status_label' => $appointment->status->label(),
            'service_reminder_id' => $appointment->service_reminder_id,
            'created_by' => $appointment->creator?->name,
            'customer' => [
                'id' => $appointment->customer->id,
                'name' => $appointment->customer->trade_name ?: $appointment->customer->name,
                'phone' => $appointment->customer->phone,
                'phone_is_whatsapp' => $appointment->customer->phone_is_whatsapp,
                'deleted' => $appointment->customer->trashed(),
            ],
            'vehicle' => $appointment->vehicle ? [
                'id' => $appointment->vehicle->id,
                'brand' => $appointment->vehicle->brand,
                'model' => $appointment->vehicle->model,
                'plate' => $appointment->vehicle->plate,
            ] : null,
            'service_order' => $appointment->relationLoaded('serviceOrder') && $appointment->serviceOrder
                ? ['id' => $appointment->serviceOrder->id, 'number' => $appointment->serviceOrder->number()]
                : null,
        ];
    }
}
