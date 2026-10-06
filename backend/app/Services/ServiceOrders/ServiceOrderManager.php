<?php

namespace App\Services\ServiceOrders;

use App\Enums\ServiceOrderStatus;
use App\Models\LaborService;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\ServiceOrderPart;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Regras da ordem de serviço e do orçamento.
 *
 * Fluxo: entrada do veículo (Aberta) → diagnóstico (serviços e peças, sem valores) → orçamento
 * (valores) → (envia) Aguardando aprovação → (cliente aprova) Em andamento → Concluída → Entregue.
 * O serviço só entra em execução com o orçamento aprovado. Toda alteração relevante vira um
 * evento na linha do tempo, na mesma transação da alteração.
 */
class ServiceOrderManager
{
    /**
     * @param  array<string, mixed>  $data  dados validados (SaveServiceOrderRequest)
     */
    public function create(array $data, User $actor): ServiceOrder
    {
        return DB::transaction(function () use ($data, $actor) {
            $status = ServiceOrderStatus::from($data['status'] ?? ServiceOrderStatus::Open->value);

            $order = new ServiceOrder($data);
            $order->customer_id = $data['customer_id'];
            $order->vehicle_id = $data['vehicle_id'];
            $order->status = ServiceOrderStatus::Open;
            $order->created_by = $actor->id;
            $order->save();

            $this->syncItems($order, $data['items'] ?? [], $actor, logChanges: false);
            $this->syncParts($order, $data['parts'] ?? [], $actor, logChanges: false);
            $this->recalculateTotals($order);
            $this->bumpVehicleMileage($order);

            if ($status === ServiceOrderStatus::InProgress) {
                $this->ensureHasBudget($order);

                // Cliente já aprovou no balcão: OS nasce aprovada e em andamento
                $order->budget_approved_at = now();
                $order->budget_approved_total_cents = $order->total_cents;
                $order->status = ServiceOrderStatus::InProgress;
                $this->applyStatusTimestamps($order, ServiceOrderStatus::InProgress);
                $order->save();

                $this->record($order, $actor, 'created', 'OS aberta com orçamento aprovado pelo cliente ('.Money::format($order->total_cents).') e serviço iniciado.', to: $order->status);
            } else {
                $this->record($order, $actor, 'created', 'OS aberta: veículo deu entrada na oficina.', to: $order->status);
            }

            return $order;
        });
    }

    /**
     * Atualiza o que veio na requisição: dados de entrada e/ou serviços, peças e desconto (orçamento).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ServiceOrder $order, array $data, User $actor): ServiceOrder
    {
        $this->ensureEditable($order);

        return DB::transaction(function () use ($order, $data, $actor) {
            $previousTotal = $order->total_cents;

            $order->fill($data);
            $changed = array_values(array_diff(array_keys($order->getDirty()), ['discount_cents']));
            $order->save();

            if ($changed !== []) {
                $labels = ['mileage' => 'km de entrada', 'complaint' => 'relato do cliente', 'notes' => 'observações', 'expected_at' => 'previsão de entrega'];
                $fields = implode(', ', array_map(fn (string $field) => $labels[$field] ?? $field, $changed));
                $this->record($order, $actor, 'updated', "Dados atualizados: {$fields}.");
            }

            if (array_key_exists('items', $data)) {
                $this->syncItems($order, $data['items'], $actor, logChanges: true);
            }
            if (array_key_exists('parts', $data)) {
                $this->syncParts($order, $data['parts'], $actor, logChanges: true);
            }
            $this->recalculateTotals($order);
            $this->bumpVehicleMileage($order);

            if ($order->total_cents !== $previousTotal) {
                $message = 'Orçamento atualizado: '.Money::format($previousTotal).' → '.Money::format($order->total_cents).'.';
                if ($order->budgetChangedAfterApproval()) {
                    $message .= "\nAtenção: o valor aprovado pelo cliente foi ".Money::format($order->budget_approved_total_cents).'. Confirme a mudança com ele.';
                }
                $this->record($order, $actor, 'budget_updated', $message);
            }

            return $order;
        });
    }

    /** Orçamento enviado ao cliente: OS passa a aguardar a aprovação. */
    public function sendBudget(ServiceOrder $order, User $actor): ServiceOrder
    {
        $this->ensureEditable($order);
        $this->ensureHasBudget($order);

        if ($order->isBudgetApproved() && ! $order->budgetChangedAfterApproval()) {
            throw ValidationException::withMessages(['budget' => 'O orçamento já foi aprovado pelo cliente.']);
        }

        return DB::transaction(function () use ($order, $actor) {
            $from = $order->status;
            $order->budget_sent_at = now();
            if (in_array($from, [ServiceOrderStatus::Open, ServiceOrderStatus::WaitingApproval], true)) {
                $order->status = ServiceOrderStatus::WaitingApproval;
            }
            $order->save();

            $this->record($order, $actor, 'budget_sent', 'Orçamento enviado ao cliente: '.Money::format($order->total_cents).'.', $from, $order->status);

            return $order;
        });
    }

    /** Cliente aprovou: registra o valor aprovado e inicia o serviço (se ainda não iniciado). */
    public function approveBudget(ServiceOrder $order, ?string $note, User $actor): ServiceOrder
    {
        $this->ensureEditable($order);
        $this->ensureHasBudget($order);

        return DB::transaction(function () use ($order, $note, $actor) {
            $from = $order->status;
            $order->budget_approved_at = now();
            $order->budget_approved_total_cents = $order->total_cents;

            if (in_array($from, [ServiceOrderStatus::Open, ServiceOrderStatus::WaitingApproval], true)) {
                $order->status = ServiceOrderStatus::InProgress;
                $this->applyStatusTimestamps($order, ServiceOrderStatus::InProgress);
            }
            $order->save();

            $description = 'Orçamento aprovado pelo cliente: '.Money::format($order->total_cents).'.'
                .($order->status !== $from ? ' Serviço iniciado.' : '')
                .($note ? "\n{$note}" : '');
            $this->record($order, $actor, 'budget_approved', $description, $from, $order->status);

            return $order;
        });
    }

    /** Cliente recusou: volta para revisão do orçamento ou cancela a OS. */
    public function rejectBudget(ServiceOrder $order, ?string $note, bool $cancel, User $actor): ServiceOrder
    {
        $this->ensureEditable($order);

        if ($order->status->requiresApprovedBudget() && ! $order->budgetChangedAfterApproval()) {
            throw ValidationException::withMessages(['budget' => 'O serviço já está em execução com o orçamento aprovado.']);
        }

        return DB::transaction(function () use ($order, $note, $cancel, $actor) {
            $from = $order->status;
            $order->budget_approved_at = null;
            $order->budget_approved_total_cents = null;
            $order->status = $cancel ? ServiceOrderStatus::Canceled : ServiceOrderStatus::Open;
            $this->applyStatusTimestamps($order, $order->status, $from);
            $order->save();

            $description = 'Orçamento recusado pelo cliente ('.Money::format($order->total_cents).'). '
                .($cancel ? 'OS cancelada.' : 'Orçamento em revisão.')
                .($note ? "\n{$note}" : '');
            $this->record($order, $actor, 'budget_rejected', $description, $from, $order->status);

            return $order;
        });
    }

    public function changeStatus(ServiceOrder $order, ServiceOrderStatus $status, ?string $note, User $actor): ServiceOrder
    {
        if ($order->status === $status) {
            throw ValidationException::withMessages(['status' => 'A OS já está com este status.']);
        }

        if ($status->requiresApprovedBudget() && ! $order->isBudgetApproved()) {
            throw ValidationException::withMessages([
                'status' => 'Registre a aprovação do orçamento pelo cliente antes de iniciar o serviço.',
            ]);
        }

        return DB::transaction(function () use ($order, $status, $note, $actor) {
            $from = $order->status;
            $order->status = $status;
            $this->applyStatusTimestamps($order, $status, $from);
            $order->save();

            $description = $from->isFinal() && ! $status->isFinal()
                ? "OS reaberta: {$from->label()} → {$status->label()}."
                : "Status alterado: {$from->label()} → {$status->label()}.";

            $this->record($order, $actor, 'status_changed', $description.($note ? "\n{$note}" : ''), $from, $status);

            return $order;
        });
    }

    public function setItemDone(ServiceOrder $order, ServiceOrderItem $item, bool $done, User $actor): ServiceOrderItem
    {
        $this->ensureEditable($order);

        if ($done && ! $order->isBudgetApproved()) {
            throw ValidationException::withMessages(['status' => 'Aguarde a aprovação do orçamento para marcar serviços como feitos.']);
        }

        if ($item->is_done === $done) {
            return $item;
        }

        return DB::transaction(function () use ($order, $item, $done, $actor) {
            $item->is_done = $done;
            $item->done_at = $done ? now() : null;
            $item->done_by = $done ? $actor->id : null;
            $item->save();

            $this->record($order, $actor, $done ? 'item_done' : 'item_undone', $done
                ? "Serviço concluído: {$item->name}."
                : "Serviço marcado como pendente novamente: {$item->name}.");

            return $item;
        });
    }

    public function addNote(ServiceOrder $order, string $note, User $actor): void
    {
        $this->record($order, $actor, 'note', $note);
    }

    /** Diagnóstico: serviço que precisa ser feito (valor definido depois, no orçamento). */
    public function addItem(ServiceOrder $order, LaborService $service, ?string $notes, User $actor): ServiceOrderItem
    {
        $this->ensureEditable($order);

        return DB::transaction(function () use ($order, $service, $notes, $actor) {
            $item = $order->items()->create([
                'labor_service_id' => $service->id,
                'name' => $service->name,
                'notes' => $notes,
                'position' => (int) $order->items()->max('position') + 1,
            ]);
            $this->record($order, $actor, 'item_added', "Serviço adicionado: {$item->name}.");

            return $item;
        });
    }

    public function removeItem(ServiceOrder $order, ServiceOrderItem $item, User $actor): void
    {
        $this->ensureEditable($order);

        if ($item->is_done) {
            throw ValidationException::withMessages(['item' => 'Serviço já feito. Desmarque-o antes de remover.']);
        }

        DB::transaction(function () use ($order, $item, $actor) {
            $item->delete();
            $this->recalculateTotals($order);
            $this->record($order, $actor, 'item_removed', "Serviço removido: {$item->name}.");
        });
    }

    /**
     * Peça necessária para o serviço (valor definido depois, no orçamento).
     *
     * @param  array{name: string, part_number?: string|null, quantity: float|string, unit_price_cents?: int|null}  $data
     */
    public function addPart(ServiceOrder $order, array $data, User $actor): ServiceOrderPart
    {
        $this->ensureEditable($order);

        return DB::transaction(function () use ($order, $data, $actor) {
            $part = $order->parts()->create([
                'name' => $data['name'],
                'part_number' => $data['part_number'] ?? null,
                'quantity' => $data['quantity'],
                'unit_price_cents' => $data['unit_price_cents'] ?? null,
                'position' => (int) $order->parts()->max('position') + 1,
            ]);
            $this->recalculateTotals($order);
            $this->record($order, $actor, 'part_added', 'Peça adicionada: '.$this->describePart($part).'.');

            return $part;
        });
    }

    public function removePart(ServiceOrder $order, ServiceOrderPart $part, User $actor): void
    {
        $this->ensureEditable($order);

        DB::transaction(function () use ($order, $part, $actor) {
            $part->delete();
            $this->recalculateTotals($order);
            $this->record($order, $actor, 'part_removed', 'Peça removida: '.$this->describePart($part).'.');
        });
    }

    /**
     * Serviços: com id atualizam (só os campos enviados), sem id entram, ausentes saem.
     *
     * @param  list<array{id?: int|null, labor_service_id?: int|null, notes?: string|null, price_cents?: int|null}>  $items
     */
    private function syncItems(ServiceOrder $order, array $items, User $actor, bool $logChanges): void
    {
        $keptIds = [];

        foreach (array_values($items) as $position => $itemData) {
            $attributes = ['position' => $position];
            if (array_key_exists('notes', $itemData)) {
                $attributes['notes'] = $itemData['notes'];
            }
            if (array_key_exists('price_cents', $itemData)) {
                $attributes['price_cents'] = $itemData['price_cents'] === null ? null : (int) $itemData['price_cents'];
            }

            if (! empty($itemData['id'])) {
                $item = $order->items()->findOrFail($itemData['id']);
                $item->update($attributes);
            } else {
                $service = LaborService::findOrFail($itemData['labor_service_id']);
                $item = $order->items()->create([
                    'notes' => null,
                    'price_cents' => null,
                    ...$attributes,
                    'labor_service_id' => $service->id,
                    'name' => $service->name,
                ]);

                if ($logChanges) {
                    $this->record($order, $actor, 'item_added', "Serviço adicionado: {$item->name}.");
                }
            }

            $keptIds[] = $item->id;
        }

        foreach ($order->items()->whereNotIn('id', $keptIds)->get() as $item) {
            $item->delete();
            $this->record($order, $actor, 'item_removed', "Serviço removido: {$item->name}.");
        }
    }

    /**
     * Peças: com id atualizam, sem id entram, ausentes saem. Valor unitário ausente = mantém o atual.
     *
     * @param  list<array{id?: int|null, name: string, part_number?: string|null, quantity: float|string, unit_price_cents?: int|null}>  $parts
     */
    private function syncParts(ServiceOrder $order, array $parts, User $actor, bool $logChanges): void
    {
        $keptIds = [];

        foreach (array_values($parts) as $position => $partData) {
            $attributes = [
                'name' => $partData['name'],
                'part_number' => $partData['part_number'] ?? null,
                'quantity' => $partData['quantity'],
                'position' => $position,
            ];
            if (array_key_exists('unit_price_cents', $partData)) {
                $attributes['unit_price_cents'] = $partData['unit_price_cents'] === null ? null : (int) $partData['unit_price_cents'];
            }

            if (! empty($partData['id'])) {
                $part = tap($order->parts()->findOrFail($partData['id']))->update($attributes);
            } else {
                $part = $order->parts()->create(['unit_price_cents' => null, ...$attributes]);
                if ($logChanges) {
                    $this->record($order, $actor, 'part_added', 'Peça adicionada: '.$this->describePart($part).'.');
                }
            }

            $keptIds[] = $part->id;
        }

        foreach ($order->parts()->whereNotIn('id', $keptIds)->get() as $part) {
            $part->delete();
            if ($logChanges) {
                $this->record($order, $actor, 'part_removed', 'Peça removida: '.$this->describePart($part).'.');
            }
        }
    }

    private function describePart(ServiceOrderPart $part): string
    {
        return Money::quantity($part->quantity).'× '.$part->name;
    }

    private function recalculateTotals(ServiceOrder $order): void
    {
        $labor = (int) $order->items()->sum('price_cents');
        $parts = $order->parts()->get()->sum(fn (ServiceOrderPart $part) => $part->totalCents() ?? 0);
        $subtotal = $labor + $parts;

        if ($order->discount_cents > $subtotal) {
            throw ValidationException::withMessages(['discount_cents' => 'O desconto não pode ser maior que o valor do orçamento ('.Money::format($subtotal).').']);
        }

        $order->labor_total_cents = $labor;
        $order->parts_total_cents = $parts;
        $order->total_cents = $subtotal - $order->discount_cents;
        $order->save();
    }

    /** Datas de início/conclusão/entrega/cancelamento acompanham o status. */
    private function applyStatusTimestamps(ServiceOrder $order, ServiceOrderStatus $status, ?ServiceOrderStatus $from = null): void
    {
        $now = now();

        if ($status->requiresApprovedBudget()) {
            $order->started_at ??= $now;
        }
        if ($status === ServiceOrderStatus::Completed || $status === ServiceOrderStatus::Delivered) {
            $order->completed_at ??= $now;
        }
        if ($status === ServiceOrderStatus::Delivered) {
            $order->delivered_at = $now;
        }
        if ($status === ServiceOrderStatus::Canceled) {
            $order->canceled_at = $now;
        }

        // Reabertura: limpa as marcas de encerramento
        if ($from?->isFinal() && ! $status->isFinal()) {
            $order->delivered_at = null;
            $order->canceled_at = null;
        }
        if (in_array($status, [ServiceOrderStatus::Open, ServiceOrderStatus::InProgress, ServiceOrderStatus::WaitingApproval, ServiceOrderStatus::WaitingParts], true)) {
            $order->completed_at = null;
        }
    }

    /** Km de entrada maior que o cadastrado atualiza a quilometragem do veículo. */
    private function bumpVehicleMileage(ServiceOrder $order): void
    {
        if ($order->mileage === null) {
            return;
        }

        Vehicle::whereKey($order->vehicle_id)
            ->where(fn ($query) => $query->whereNull('mileage')->orWhere('mileage', '<', $order->mileage))
            ->update(['mileage' => $order->mileage]);
    }

    private function ensureEditable(ServiceOrder $order): void
    {
        if ($order->status->isFinal()) {
            throw ValidationException::withMessages([
                'status' => "A OS está {$order->status->label()}. Reabra a OS para alterar.",
            ]);
        }
    }

    /** Orçamento completo: ao menos um serviço/peça e todos com valor definido. */
    private function ensureHasBudget(ServiceOrder $order): void
    {
        if (! $order->items()->exists() && ! $order->parts()->exists()) {
            throw ValidationException::withMessages(['budget' => 'Adicione os serviços e peças antes de enviar ou aprovar o orçamento.']);
        }

        $unpriced = $order->items()->whereNull('price_cents')->count() + $order->parts()->whereNull('unit_price_cents')->count();
        if ($unpriced > 0) {
            throw ValidationException::withMessages([
                'budget' => $unpriced === 1
                    ? 'Há 1 item sem valor. Monte o orçamento antes de enviar ou aprovar.'
                    : "Há {$unpriced} itens sem valor. Monte o orçamento antes de enviar ou aprovar.",
            ]);
        }
    }

    private function record(
        ServiceOrder $order,
        User $actor,
        string $type,
        string $description,
        ?ServiceOrderStatus $from = null,
        ?ServiceOrderStatus $to = null,
    ): void {
        $order->events()->create([
            'user_id' => $actor->id,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'description' => $description,
        ]);
    }
}
