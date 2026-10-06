<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Customers\SaveCustomer;
use App\Enums\AppointmentStatus;
use App\Enums\PersonType;
use App\Enums\ReminderStatus;
use App\Enums\VehicleType;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Customer;
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

    /**
     * O carro chegou: abre a OS (entrada) a partir do agendamento.
     *
     * Quem foi agendado sem cadastro vira cliente aqui: escolhe um cliente existente (`customer_id`)
     * ou cadastra na hora (`customer`: nome e telefone). O veículo pode ser um do cliente
     * (`vehicle_id`) ou um novo (`vehicle`: tipo, marca, modelo e placa).
     */
    public function checkIn(Request $request, Appointment $appointment, ServiceOrderManager $orders, SaveCustomer $saveCustomer): JsonResponse
    {
        $this->ensureOpen($appointment);

        $digits = fn (mixed $value) => ($value = preg_replace('/\D/', '', (string) $value)) === '' ? null : $value;
        $request->merge(array_filter([
            'customer' => is_array($request->input('customer')) ? [
                ...$request->input('customer'),
                'name' => trim((string) $request->input('customer.name')) ?: null,
                'phone' => $digits($request->input('customer.phone')),
            ] : null,
            'vehicle' => is_array($request->input('vehicle')) ? [
                ...$request->input('vehicle'),
                'brand' => trim((string) $request->input('vehicle.brand')) ?: null,
                'model' => trim((string) $request->input('vehicle.model')) ?: null,
                'plate' => ($plate = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $request->input('vehicle.plate')))) === '' ? null : $plate,
            ] : null,
        ], fn ($value) => $value !== null));

        $guest = $appointment->customer_id === null;
        $customerId = $guest ? $request->integer('customer_id') : $appointment->customer_id;
        $newCustomer = $guest && ! $request->filled('customer_id');
        $needsVehicle = $appointment->vehicle_id === null;

        $data = $request->validate([
            'customer_id' => [$guest ? 'nullable' : 'prohibited', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'customer' => [$newCustomer ? 'required' : 'prohibited', 'array'],
            'customer.name' => [Rule::requiredIf($newCustomer), 'nullable', 'string', 'max:150'],
            'customer.phone' => [Rule::requiredIf($newCustomer), 'nullable', 'regex:/^[1-9]{2}(9\d{8}|[2-8]\d{7})$/'],
            'customer.phone_is_whatsapp' => ['nullable', 'boolean'],
            'vehicle_id' => [
                Rule::requiredIf($needsVehicle && ! $request->has('vehicle')), 'nullable', 'integer',
                Rule::exists('vehicles', 'id')->where('customer_id', $customerId)->whereNull('deleted_at'),
            ],
            'vehicle' => ['nullable', 'array', 'prohibits:vehicle_id'],
            'vehicle.type' => ['required_with:vehicle', Rule::enum(VehicleType::class)],
            'vehicle.brand' => ['required_with:vehicle', 'nullable', 'string', 'max:80'],
            'vehicle.model' => ['required_with:vehicle', 'nullable', 'string', 'max:150'],
            'vehicle.plate' => ['nullable', 'regex:/^[A-Z]{3}\d[A-Z0-9]\d{2}$/', Rule::unique('vehicles', 'plate')->whereNull('deleted_at')],
            'mileage' => ['nullable', 'integer', 'between:0,9999999'],
        ], [
            'customer_id.exists' => 'Cliente não encontrado.',
            'customer.required' => 'Escolha o cliente ou cadastre na hora.',
            'customer.name.required' => 'Informe o nome do cliente.',
            'customer.phone.required' => 'Informe o telefone do cliente (com DDD).',
            'customer.phone.regex' => 'Telefone inválido: use DDD + número.',
            'vehicle_id.required' => 'Selecione o veículo que chegou ou cadastre um novo.',
            'vehicle_id.exists' => 'Este veículo não pertence ao cliente.',
            'vehicle.prohibits' => 'Escolha um veículo do cliente ou cadastre um novo, não os dois.',
            'vehicle.type.*' => 'Informe o tipo do veículo.',
            'vehicle.brand.required_with' => 'Informe a marca do veículo.',
            'vehicle.model.required_with' => 'Informe o modelo do veículo.',
            'vehicle.plate.regex' => 'Placa inválida. Use ABC1234 ou ABC1D23.',
            'vehicle.plate.unique' => 'Esta placa já está cadastrada: escolha o cliente dono do veículo.',
            'mileage.*' => 'Quilometragem inválida.',
        ]);

        if (! $guest && $appointment->customer?->trashed()) {
            throw ValidationException::withMessages(['customer' => 'O cliente foi removido do cadastro.']);
        }

        $order = DB::transaction(function () use ($appointment, $data, $request, $orders, $saveCustomer, $newCustomer) {
            // Quem não tinha cadastro: cliente existente ou cadastrado agora (com o carro novo, se veio)
            $customer = match (true) {
                $newCustomer => $saveCustomer->handle([
                    'person_type' => PersonType::Individual->value,
                    'name' => $data['customer']['name'],
                    'phone' => $data['customer']['phone'],
                    'phone_is_whatsapp' => (bool) ($data['customer']['phone_is_whatsapp'] ?? true),
                    'notes' => 'Cadastrado no check-in do agendamento.',
                    'vehicles' => [],
                ], actor: $request->user()),
                $appointment->customer_id === null => Customer::findOrFail($data['customer_id']),
                default => $appointment->customer,
            };

            $vehicleId = $data['vehicle_id'] ?? $appointment->vehicle_id;
            if (! empty($data['vehicle'])) {
                $vehicleId = $customer->vehicles()->create([
                    'type' => $data['vehicle']['type'],
                    'brand' => $data['vehicle']['brand'],
                    'model' => $data['vehicle']['model'],
                    'plate' => $data['vehicle']['plate'] ?? null,
                    'mileage' => $data['mileage'] ?? null,
                ])->id;
            }

            $order = $orders->create([
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicleId,
                'mileage' => $data['mileage'] ?? null,
                'complaint' => $appointment->notes,
            ], $request->user());
            $orders->addNote($order, 'Veículo agendado para '.$appointment->scheduled_at->timezone(config('jetcar.timezone'))->format('d/m/Y \\à\\s H:i').'.', $request->user());

            $appointment->forceFill([
                'status' => AppointmentStatus::Arrived,
                'service_order_id' => $order->id,
                'customer_id' => $customer->id,
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
        $text = fn (mixed $value) => trim((string) $value) ?: null;
        $request->merge([
            'notes' => $text($request->input('notes')),
            'contact_name' => $text($request->input('contact_name')),
            'contact_phone' => ($phone = preg_replace('/\D/', '', (string) $request->input('contact_phone'))) === '' ? null : $phone,
            'vehicle_description' => $text($request->input('vehicle_description')),
        ]);
        $customerId = (int) $request->input('customer_id', $appointment?->customer_id);

        $data = $request->validate([
            // Cliente cadastrado ou, para quem ainda não tem cadastro, o nome digitado
            'customer_id' => ['nullable', 'required_without:contact_name', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'regex:/^[1-9]{2}(9\d{8}|[2-8]\d{7})$/'],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->where('customer_id', $customerId)->whereNull('deleted_at')],
            'vehicle_description' => ['nullable', 'string', 'max:120'],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'between:15,600'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'service_reminder_id' => [$appointment ? 'prohibited' : 'nullable', 'integer', Rule::exists('service_reminders', 'id')->where('customer_id', $customerId)],
        ], [
            'customer_id.required_without' => 'Selecione o cliente ou digite o nome de quem vem.',
            'customer_id.exists' => 'Cliente não encontrado.',
            'contact_phone.regex' => 'Telefone inválido: use DDD + número.',
            'vehicle_id.exists' => 'Este veículo não pertence ao cliente.',
            'scheduled_at.required' => 'Informe a data e o horário.',
            'scheduled_at.date' => 'Data inválida.',
            'duration_minutes.*' => 'Duração: de 15 minutos a 10 horas.',
            'service_reminder_id.exists' => 'Lembrete não encontrado para este cliente.',
        ]);

        // Com cliente cadastrado, os dados digitados não valem; sem cliente, não há veículo cadastrado
        if (! empty($data['customer_id'])) {
            $data['contact_name'] = null;
            $data['contact_phone'] = null;
            $data['vehicle_description'] = empty($data['vehicle_id']) ? ($data['vehicle_description'] ?? null) : null;
        } else {
            $data['customer_id'] = null;
            $data['vehicle_id'] = null;
        }

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
            'customer' => $appointment->customer ? [
                'id' => $appointment->customer->id,
                'name' => $appointment->customer->trade_name ?: $appointment->customer->name,
                'phone' => $appointment->customer->phone,
                'phone_is_whatsapp' => $appointment->customer->phone_is_whatsapp,
                'deleted' => $appointment->customer->trashed(),
            ] : null,
            // Sem cadastro: o que foi digitado no agendamento (vira cliente no check-in)
            'contact' => $appointment->customer_id === null ? [
                'name' => $appointment->contact_name,
                'phone' => $appointment->contact_phone,
            ] : null,
            'display_name' => $appointment->displayName(),
            'vehicle_description' => $appointment->vehicle_description,
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
