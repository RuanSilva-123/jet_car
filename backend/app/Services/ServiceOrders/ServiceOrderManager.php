<?php

namespace App\Services\ServiceOrders;

use App\Enums\ServiceOrderStatus;
use App\Models\LaborService;
use App\Models\Part;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\ServiceOrderPart;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Inventory\StockManager;
use App\Support\Money;
use App\Support\ShopSettings;
use Illuminate\Database\Eloquent\Collection;
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
    public function __construct(private readonly StockManager $stock) {}

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

    /**
     * Cliente aprovou: registra o valor aprovado e inicia o serviço (se ainda não iniciado).
     * Sem $actor = o próprio cliente respondeu pelo link público do orçamento.
     */
    public function approveBudget(ServiceOrder $order, ?string $note, ?User $actor): ServiceOrder
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

            $description = ($actor ? 'Orçamento aprovado pelo cliente: ' : 'Orçamento aprovado pelo cliente pelo link: ').Money::format($order->total_cents).'.'
                .($order->status !== $from ? ' Serviço iniciado.' : '')
                .($note ? "\n{$note}" : '');
            $this->record($order, $actor, 'budget_approved', $description, $from, $order->status);

            return $order;
        });
    }

    /** Cliente recusou: volta para revisão do orçamento ou cancela a OS. Sem $actor = pelo link público. */
    public function rejectBudget(ServiceOrder $order, ?string $note, bool $cancel, ?User $actor): ServiceOrder
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
            $this->syncStockWithStatus($order, $from, $order->status, $actor);

            $description = ($actor ? 'Orçamento recusado pelo cliente (' : 'Orçamento recusado pelo cliente pelo link (').Money::format($order->total_cents).'). '
                .($cancel ? 'OS cancelada.' : 'Orçamento em revisão.')
                .($note ? "\n{$note}" : '');
            $this->record($order, $actor, 'budget_rejected', $description, $from, $order->status);

            return $order;
        });
    }

    /**
     * OS entregues do mesmo veículo ainda dentro da garantia (prazo nos dados da oficina).
     *
     * @return Collection<int, ServiceOrder>
     */
    public function warrantyCandidates(ServiceOrder $order): Collection
    {
        $days = (int) ShopSettings::get()['warranty_days'];
        if ($days <= 0) {
            return new Collection;
        }

        return ServiceOrder::query()
            ->where('vehicle_id', $order->vehicle_id)
            ->whereKeyNot($order->id)
            ->where('status', ServiceOrderStatus::Delivered->value)
            ->where('delivered_at', '>=', now()->subDays($days)->startOfDay())
            ->with(['items' => fn ($query) => $query->where('is_done', true)])
            ->latest('delivered_at')
            ->get();
    }

    /**
     * Marca a OS como retorno em garantia da OS original. Os serviços refeitos entram sem custo,
     * ligados ao serviço original (base do relatório de retornos).
     *
     * @param  list<int>  $itemIds  serviços da OS original que estão sendo refeitos
     */
    public function linkWarranty(ServiceOrder $order, ServiceOrder $original, array $itemIds, User $actor): ServiceOrder
    {
        $this->ensureEditable($order);

        if ($order->warranty_of_id !== null) {
            throw ValidationException::withMessages(['warranty_of_id' => 'Esta OS já é retorno em garantia da OS #'.$order->warrantyOf?->number().'. Desfaça o vínculo para trocar.']);
        }
        if (! $this->warrantyCandidates($order)->contains('id', $original->id)) {
            throw ValidationException::withMessages(['warranty_of_id' => 'A OS escolhida não é deste veículo, não foi entregue ou já está fora da garantia.']);
        }

        $items = $original->items()->whereIn('id', $itemIds)->where('is_done', true)->get();
        if ($items->count() !== count(array_unique($itemIds))) {
            throw ValidationException::withMessages(['item_ids' => 'Escolha serviços feitos na OS original.']);
        }

        return DB::transaction(function () use ($order, $original, $items, $actor) {
            $order->warranty_of_id = $original->id;
            $order->save();

            $position = (int) $order->items()->max('position');
            foreach ($items as $item) {
                $order->items()->create([
                    'labor_service_id' => $item->labor_service_id,
                    'warranty_of_item_id' => $item->id,
                    'name' => $item->name,
                    'notes' => 'Garantia da OS #'.$original->number(),
                    // Retorno em garantia não é cobrado
                    'price_cents' => 0,
                    'position' => ++$position,
                ]);
            }
            $this->recalculateTotals($order);

            $names = $items->pluck('name')->implode(', ');
            $this->record($order, $actor, 'warranty_linked', 'Retorno em garantia da OS #'.$original->number().($names ? ". Serviços refeitos sem custo: {$names}." : '.'));
            $this->record($original, $actor, 'warranty_return', 'O veículo voltou em garantia: OS #'.$order->number().($names ? " ({$names})." : '.'));

            return $order;
        });
    }

    /** Desfaz o vínculo de garantia (os serviços de garantia ainda não feitos saem da OS). */
    public function unlinkWarranty(ServiceOrder $order, User $actor): ServiceOrder
    {
        $this->ensureEditable($order);
        $original = $order->warrantyOf;
        if (! $original) {
            throw ValidationException::withMessages(['warranty_of_id' => 'Esta OS não é retorno em garantia.']);
        }
        if ($order->items()->whereNotNull('warranty_of_item_id')->where('is_done', true)->exists()) {
            throw ValidationException::withMessages(['warranty_of_id' => 'Há serviço de garantia já feito. Desmarque-o antes de desfazer o vínculo.']);
        }

        return DB::transaction(function () use ($order, $original, $actor) {
            $order->items()->whereNotNull('warranty_of_item_id')->delete();
            $order->warranty_of_id = null;
            $order->save();
            $this->recalculateTotals($order);

            $this->record($order, $actor, 'warranty_unlinked', 'Deixou de ser retorno em garantia da OS #'.$original->number().'.');
            $this->record($original, $actor, 'warranty_unlinked', 'OS #'.$order->number().' não é mais retorno em garantia desta OS.');

            return $order;
        });
    }

    /** O orçamento pode receber a resposta do cliente (aprovar/recusar)? */
    public function awaitsDecision(ServiceOrder $order): bool
    {
        if ($order->status->isFinal() || $order->unpricedCount() > 0 || (! $order->items()->exists() && ! $order->parts()->exists())) {
            return false;
        }

        return in_array($order->status, [ServiceOrderStatus::Open, ServiceOrderStatus::WaitingApproval], true)
            || $order->budgetChangedAfterApproval();
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
            $this->syncStockWithStatus($order, $from, $status, $actor);

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

    /** Define (ou remove) o mecânico responsável pelo serviço. */
    public function assignMechanic(ServiceOrder $order, ServiceOrderItem $item, ?User $mechanic, User $actor): ServiceOrderItem
    {
        if ($item->mechanic_id === $mechanic?->id) {
            return $item;
        }

        return DB::transaction(function () use ($order, $item, $mechanic, $actor) {
            $item->mechanic_id = $mechanic?->id;
            $item->save();

            $this->record($order, $actor, 'item_assigned', $mechanic
                ? "Serviço atribuído a {$mechanic->name}: {$item->name}."
                : "Serviço sem responsável: {$item->name}.");

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
     * Peça do estoque (part_id): nome, código e preço de venda vêm do catálogo quando não
     * informados, e a quantidade sai do estoque.
     *
     * @param  array{part_id?: int|null, name?: string|null, part_number?: string|null, quantity: float|string, unit_price_cents?: int|null}  $data
     */
    public function addPart(ServiceOrder $order, array $data, User $actor): ServiceOrderPart
    {
        $this->ensureEditable($order);

        return DB::transaction(function () use ($order, $data, $actor) {
            $catalog = empty($data['part_id']) ? null : Part::findOrFail($data['part_id']);

            $part = $order->parts()->create([
                'part_id' => $catalog?->id,
                'name' => ($data['name'] ?? null) ?: $catalog?->name,
                'part_number' => ($data['part_number'] ?? null) ?: $catalog?->part_number,
                'quantity' => $data['quantity'],
                'unit_price_cents' => $data['unit_price_cents'] ?? $catalog?->price_cents,
                'position' => (int) $order->parts()->max('position') + 1,
            ]);
            if ($catalog) {
                $this->stock->forOrder($catalog, (float) $part->quantity, $order, $actor);
            }
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
            $this->returnToStock($order, $part, $actor);
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
                $part = $order->parts()->findOrFail($partData['id']);
                $previousQuantity = (float) $part->quantity;
                $part->update($attributes);

                // Mudou a quantidade de uma peça do estoque: baixa/devolve só a diferença
                if ($part->part_id && $part->catalogPart) {
                    $this->stock->forOrder($part->catalogPart, (float) $part->quantity - $previousQuantity, $order, $actor);
                }
            } else {
                $catalog = empty($partData['part_id']) ? null : Part::findOrFail($partData['part_id']);
                $part = $order->parts()->create([
                    'unit_price_cents' => $catalog?->price_cents,
                    ...$attributes,
                    'part_id' => $catalog?->id,
                ]);
                if ($catalog) {
                    $this->stock->forOrder($catalog, (float) $part->quantity, $order, $actor);
                }
                if ($logChanges) {
                    $this->record($order, $actor, 'part_added', 'Peça adicionada: '.$this->describePart($part).'.');
                }
            }

            $keptIds[] = $part->id;
        }

        foreach ($order->parts()->whereNotIn('id', $keptIds)->get() as $part) {
            $part->delete();
            $this->returnToStock($order, $part, $actor);
            if ($logChanges) {
                $this->record($order, $actor, 'part_removed', 'Peça removida: '.$this->describePart($part).'.');
            }
        }
    }

    /** Peça do estoque saiu da OS: a quantidade volta para o estoque. */
    private function returnToStock(ServiceOrder $order, ServiceOrderPart $part, ?User $actor): void
    {
        if ($part->part_id && $part->catalogPart) {
            $this->stock->forOrder($part->catalogPart, -(float) $part->quantity, $order, $actor);
        }
    }

    /** OS cancelada devolve as peças do estoque; reaberta, dá baixa de novo. */
    private function syncStockWithStatus(ServiceOrder $order, ServiceOrderStatus $from, ServiceOrderStatus $to, ?User $actor): void
    {
        $canceled = ServiceOrderStatus::Canceled;
        if (($from === $canceled) === ($to === $canceled)) {
            return;
        }

        $sign = $to === $canceled ? -1 : 1;
        foreach ($order->parts()->whereNotNull('part_id')->with('catalogPart')->get() as $part) {
            if ($part->catalogPart) {
                $this->stock->forOrder($part->catalogPart, $sign * (float) $part->quantity, $order, $actor);
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
        ?User $actor,
        string $type,
        string $description,
        ?ServiceOrderStatus $from = null,
        ?ServiceOrderStatus $to = null,
    ): void {
        $order->events()->create([
            'user_id' => $actor?->id,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'description' => $description,
        ]);
    }
}
