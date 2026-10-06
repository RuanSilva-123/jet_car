<?php

namespace App\Services\Finance;

use App\Enums\PaymentMethod;
use App\Models\Bill;
use App\Models\RecurringBill;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Contas a pagar: lançamento (à vista ou parcelado), pagamento, estorno e as contas
 * geradas todo mês pelas despesas fixas.
 */
class BillManager
{
    /**
     * Lança a conta. Com mais de uma parcela, o valor é dividido (o resto dos centavos vai
     * na primeira) e os vencimentos seguem mês a mês.
     *
     * @param  array{description: string, supplier_id?: int|null, category: string, amount_cents: int, due_date: string,
     *     installments?: int|null, document_number?: string|null, notes?: string|null, paid_at?: string|null, payment_method?: string|null}  $data
     * @return Collection<int, Bill>
     */
    public function create(array $data, User $actor): Collection
    {
        $count = max(1, (int) ($data['installments'] ?? 1));
        $base = intdiv($data['amount_cents'], $count);
        $remainder = $data['amount_cents'] - $base * $count;
        $firstDue = Carbon::parse($data['due_date']);
        $group = $count > 1 ? (string) Str::uuid() : null;

        if ($count > 1 && ! empty($data['paid_at'])) {
            throw ValidationException::withMessages(['paid_at' => 'Conta parcelada: registre o pagamento de cada parcela.']);
        }

        return DB::transaction(function () use ($data, $actor, $count, $base, $remainder, $firstDue, $group) {
            $bills = collect();
            for ($i = 1; $i <= $count; $i++) {
                $bill = new Bill([
                    'description' => $data['description'],
                    'supplier_id' => $data['supplier_id'] ?? null,
                    'category' => $data['category'],
                    'amount_cents' => $base + ($i === 1 ? $remainder : 0),
                    'due_date' => $firstDue->copy()->addMonthsNoOverflow($i - 1),
                    'document_number' => $data['document_number'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);
                $bill->installment_group = $group;
                $bill->installment_number = $count > 1 ? $i : null;
                $bill->installment_count = $count > 1 ? $count : null;
                $bill->created_by = $actor->id;
                $bill->save();
                $bills->push($bill);
            }

            // Despesa já paga (ex.: compra no balcão)
            if (! empty($data['paid_at'])) {
                $this->pay($bills->first(), $data['paid_at'], $data['payment_method'] ?? PaymentMethod::Pix->value, null, $actor);
            }

            return $bills;
        });
    }

    /** Pagamento; o valor pago pode diferir (juros, multa, desconto) e substitui o da conta. */
    public function pay(Bill $bill, string $paidAt, string $method, ?int $amountCents, User $actor): Bill
    {
        if ($bill->isPaid()) {
            throw ValidationException::withMessages(['paid_at' => 'Esta conta já está paga.']);
        }

        $bill->forceFill([
            'paid_at' => $paidAt,
            'payment_method' => PaymentMethod::from($method),
            'amount_cents' => $amountCents ?? $bill->amount_cents,
            'paid_by' => $actor->id,
        ])->save();

        return $bill;
    }

    /** Estorno: a conta volta a ficar em aberto. */
    public function unpay(Bill $bill): Bill
    {
        $bill->forceFill(['paid_at' => null, 'payment_method' => null, 'paid_by' => null])->save();

        return $bill;
    }

    /**
     * Gera a conta do mês de cada despesa fixa ativa (idempotente: uma por mês).
     * Roda todo dia pelo scheduler e logo ao cadastrar/editar uma despesa fixa.
     */
    public function generateRecurring(?Carbon $month = null, ?RecurringBill $only = null): int
    {
        $month ??= LocalTime::now();
        $created = 0;

        $recurring = $only ? collect([$only]) : RecurringBill::query()->where('is_active', true)->get();

        foreach ($recurring as $template) {
            if (! $template->appliesTo($month)) {
                continue;
            }

            $exists = Bill::query()
                ->where('recurring_bill_id', $template->id)
                ->whereDate('due_date', '>=', $month->copy()->startOfMonth()->toDateString())
                ->whereDate('due_date', '<=', $month->copy()->endOfMonth()->toDateString())
                ->exists();
            if ($exists) {
                continue;
            }

            $bill = new Bill([
                'description' => $template->description,
                'supplier_id' => $template->supplier_id,
                'category' => $template->category,
                'amount_cents' => $template->amount_cents,
                'due_date' => $template->dueDateIn($month),
                'notes' => $template->notes,
            ]);
            $bill->recurring_bill_id = $template->id;
            $bill->created_by = $template->created_by;
            $bill->save();
            $created++;
        }

        return $created;
    }
}
