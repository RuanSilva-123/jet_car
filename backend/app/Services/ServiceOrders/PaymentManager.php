<?php

namespace App\Services\ServiceOrders;

use App\Enums\PaymentMethod;
use App\Enums\ServiceOrderStatus;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderPayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recebimentos da OS. O total pago fica gravado em service_orders.paid_cents e cada
 * recebimento/estorno vira um evento na linha do tempo, na mesma transação.
 */
class PaymentManager
{
    /**
     * @param  array{method: string, amount_cents: int, installments?: int|null, paid_at?: string|null, notes?: string|null}  $data
     */
    public function register(ServiceOrder $order, array $data, User $actor): ServiceOrderPayment
    {
        if ($order->status === ServiceOrderStatus::Canceled) {
            throw ValidationException::withMessages(['amount_cents' => 'A OS está cancelada. Reabra a OS para registrar pagamentos.']);
        }

        return DB::transaction(function () use ($order, $data, $actor) {
            // Trava a linha: dois recebimentos ao mesmo tempo não podem ultrapassar o saldo
            $order = ServiceOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $balance = $order->balanceCents();

            if ($balance <= 0) {
                throw ValidationException::withMessages(['amount_cents' => $order->total_cents > 0
                    ? 'Esta OS já está paga.'
                    : 'A OS ainda não tem valor a receber.']);
            }
            if ($data['amount_cents'] > $balance) {
                throw ValidationException::withMessages(['amount_cents' => 'O valor passa do saldo em aberto ('.Money::format($balance).').']);
            }

            $method = PaymentMethod::from($data['method']);
            $payment = new ServiceOrderPayment([
                'method' => $method,
                'amount_cents' => $data['amount_cents'],
                'installments' => $method->allowsInstallments() ? max(1, (int) ($data['installments'] ?? 1)) : 1,
                'paid_at' => $data['paid_at'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]);
            $payment->received_by = $actor->id;
            $order->payments()->save($payment);

            $order->paid_cents += $payment->amount_cents;
            $order->save();

            $this->record($order, $actor, 'payment_added', 'Pagamento recebido: '.Money::format($payment->amount_cents)
                .' ('.$this->describeMethod($payment).'). '
                .($order->balanceCents() > 0 ? 'Saldo em aberto: '.Money::format($order->balanceCents()).'.' : 'OS quitada.'));

            return $payment;
        });
    }

    /** Estorno/lançamento errado: remove o recebimento e devolve o valor ao saldo. */
    public function remove(ServiceOrder $order, ServiceOrderPayment $payment, User $actor): void
    {
        DB::transaction(function () use ($order, $payment, $actor) {
            $order = ServiceOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $payment->delete();

            $order->paid_cents = max(0, $order->paid_cents - $payment->amount_cents);
            $order->save();

            $this->record($order, $actor, 'payment_removed', 'Pagamento removido: '.Money::format($payment->amount_cents)
                .' ('.$this->describeMethod($payment).', '.$payment->paid_at->format('d/m/Y').').');
        });
    }

    private function describeMethod(ServiceOrderPayment $payment): string
    {
        return $payment->method->label().($payment->installments > 1 ? " em {$payment->installments}x" : '');
    }

    private function record(ServiceOrder $order, User $actor, string $type, string $description): void
    {
        $order->events()->create(['user_id' => $actor->id, 'type' => $type, 'description' => $description]);
    }
}
