<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceOrderResource;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderPayment;
use App\Services\ServiceOrders\PaymentManager;
use App\Support\Pix\PixCharge;
use App\Support\ShopSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Recebimentos de uma OS. Registrar exige acesso ao financeiro; remover é só para o master. */
class ServiceOrderPaymentController extends Controller
{
    public function __construct(private readonly PaymentManager $payments) {}

    public function store(Request $request, ServiceOrder $serviceOrder): ServiceOrderResource
    {
        Gate::authorize('manage-finance');

        $request->merge(['notes' => trim((string) $request->input('notes')) ?: null]);
        $data = $request->validate([
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:24'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'method.required' => 'Selecione a forma de pagamento.',
            'method.enum' => 'Forma de pagamento inválida.',
            'amount_cents.required' => 'Informe o valor recebido.',
            'amount_cents.min' => 'Informe o valor recebido.',
            'amount_cents.*' => 'Valor inválido.',
            'installments.*' => 'Parcelas: de 1 a 24.',
            'paid_at.before_or_equal' => 'A data do pagamento não pode ser futura.',
            'paid_at.*' => 'Data inválida.',
        ]);

        $this->payments->register($serviceOrder, $data, $request->user());

        return $this->detail($serviceOrder);
    }

    /**
     * Cobrança Pix da OS (copia e cola + QR Code). Padrão: o saldo em aberto.
     * 404 quando a oficina não cadastrou a chave Pix ou não há o que cobrar.
     */
    public function pix(Request $request, ServiceOrder $serviceOrder): JsonResponse
    {
        Gate::authorize('manage-finance');

        $data = $request->validate([
            'amount_cents' => ['nullable', 'integer', 'min:1', 'max:'.max(1, $serviceOrder->balanceCents())],
        ], ['amount_cents.*' => 'Valor do Pix inválido (no máximo o saldo em aberto).']);

        if (! PixCharge::configured(ShopSettings::get())) {
            return response()->json(['message' => 'Cadastre a chave Pix em Dados da oficina.'], 404);
        }

        $charge = PixCharge::forOrder($serviceOrder, $data['amount_cents'] ?? null);
        abort_if($charge === null, 404, 'Não há saldo em aberto nesta OS.');

        return response()->json(['data' => $charge]);
    }

    public function destroy(Request $request, ServiceOrder $serviceOrder, ServiceOrderPayment $payment): ServiceOrderResource
    {
        Gate::authorize('delete-payment');
        abort_unless($payment->service_order_id === $serviceOrder->id, 404);

        $this->payments->remove($serviceOrder, $payment, $request->user());

        return $this->detail($serviceOrder);
    }

    private function detail(ServiceOrder $order): ServiceOrderResource
    {
        return new ServiceOrderResource($order->fresh()->load(ServiceOrderResource::DETAIL_RELATIONS));
    }
}
