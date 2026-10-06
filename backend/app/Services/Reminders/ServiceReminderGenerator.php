<?php

namespace App\Services\Reminders;

use App\Enums\ReminderStatus;
use App\Enums\ServiceOrderStatus;
use App\Models\ServiceOrderItem;
use App\Models\ServiceReminder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gera a lista de clientes a contatar. Para cada veículo e serviço periódico, olha a última
 * vez que o serviço foi feito (OS entregue) e calcula quando vence: data + meses ou km + intervalo,
 * o que vier primeiro. Vence dentro da antecedência configurada → entra na lista.
 *
 * Roda todo dia pelo scheduler (routes/console.php) e também sob demanda pelo painel.
 */
class ServiceReminderGenerator
{
    /**
     * @return array{created: int, closed: int}
     */
    public function run(?Carbon $today = null): array
    {
        $today ??= now()->startOfDay();
        $leadDays = (int) config('jetcar.reminders.lead_days');
        $leadKm = (int) config('jetcar.reminders.lead_km');

        $latest = $this->latestExecutions();
        $created = 0;
        $closed = 0;

        DB::transaction(function () use ($latest, $today, $leadDays, $leadKm, &$created, &$closed) {
            foreach ($latest as $item) {
                $order = $item->serviceOrder;
                $vehicle = $order->vehicle;
                $service = $item->laborService;

                // Serviço refeito: lembretes de execuções anteriores saem da lista
                $closed += ServiceReminder::query()
                    ->where('vehicle_id', $vehicle->id)
                    ->where('labor_service_id', $service->id)
                    ->where(fn ($query) => $query->whereNull('source_item_id')->orWhere('source_item_id', '!=', $item->id))
                    ->whereIn('status', [...ReminderStatus::openValues(), ReminderStatus::Scheduled->value])
                    ->update(['status' => ReminderStatus::Done->value, 'updated_at' => now()]);

                if ($vehicle->trashed() || $order->customer?->trashed()) {
                    continue;
                }

                $doneAt = Carbon::parse($item->done_at ?? $order->delivered_at)->startOfDay();
                $dueAt = $service->reminder_months ? $doneAt->copy()->addMonthsNoOverflow($service->reminder_months) : null;
                $dueMileage = $service->reminder_km && $order->mileage !== null ? $order->mileage + $service->reminder_km : null;

                $dueByDate = $dueAt !== null && $dueAt->lte($today->copy()->addDays($leadDays));
                $dueByKm = $dueMileage !== null && $vehicle->mileage !== null && $vehicle->mileage >= $dueMileage - $leadKm;

                if (! $dueByDate && ! $dueByKm) {
                    continue;
                }

                $reminder = ServiceReminder::firstOrCreate(['source_item_id' => $item->id], [
                    'vehicle_id' => $vehicle->id,
                    'customer_id' => $order->customer_id,
                    'labor_service_id' => $service->id,
                    'service_name' => $service->name,
                    'last_done_at' => $doneAt,
                    'last_mileage' => $order->mileage,
                    'due_at' => $dueAt,
                    'due_mileage' => $dueMileage,
                    'status' => ReminderStatus::Pending,
                ]);
                $created += $reminder->wasRecentlyCreated ? 1 : 0;
            }
        });

        return ['created' => $created, 'closed' => $closed];
    }

    /**
     * Última execução de cada serviço periódico em cada veículo.
     *
     * @return Collection<int, ServiceOrderItem>
     */
    private function latestExecutions(): Collection
    {
        return ServiceOrderItem::query()
            ->where('is_done', true)
            ->whereHas('laborService', fn ($query) => $query->where(fn ($query) => $query->whereNotNull('reminder_months')->orWhereNotNull('reminder_km')))
            ->whereHas('serviceOrder', fn ($query) => $query->where('status', ServiceOrderStatus::Delivered->value))
            ->with(['laborService', 'serviceOrder.vehicle', 'serviceOrder.customer'])
            ->get()
            ->groupBy(fn (ServiceOrderItem $item) => $item->serviceOrder->vehicle_id.'-'.$item->labor_service_id)
            ->map(fn (Collection $items) => $items->sortBy(fn (ServiceOrderItem $item) => [($item->done_at ?? $item->serviceOrder->delivered_at)?->timestamp, $item->id])->last())
            ->values();
    }
}
