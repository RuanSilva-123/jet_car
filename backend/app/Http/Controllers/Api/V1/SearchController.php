<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ServiceOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Busca global do painel (Ctrl+K). Pensada para a placa: leva direto à OS em aberto do
 * veículo, ao histórico dele ou ao cliente. Também acha cliente (nome, CPF/CNPJ, telefone)
 * e OS pelo número.
 */
class SearchController extends Controller
{
    private const LIMIT = 6;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $query = trim($data['q']);

        $plate = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($query));
        $digits = preg_replace('/\D/', '', $query);
        $term = '%'.mb_strtolower($query).'%';

        $vehicles = Vehicle::query()
            ->with('customer')
            ->whereHas('customer')
            ->where(function (Builder $vehicle) use ($plate, $term) {
                if (strlen($plate) >= 2) {
                    $vehicle->where('plate', 'like', "%{$plate}%");
                }
                $vehicle->orWhereRaw('lower(model) like ?', [$term]);
            })
            // Placa exata primeiro
            ->orderByRaw('case when plate = ? then 0 else 1 end', [$plate])
            ->orderBy('plate')
            ->limit(self::LIMIT)
            ->get();

        $activeOrders = ServiceOrder::query()
            ->whereIn('vehicle_id', $vehicles->pluck('id'))
            ->whereIn('status', ServiceOrderStatus::activeValues())
            ->latest('id')
            ->get()
            ->unique('vehicle_id')
            ->keyBy('vehicle_id');

        $customers = Customer::query()
            ->where(function (Builder $customer) use ($term, $digits, $plate) {
                $customer->whereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(trade_name) like ?', [$term]);
                if (strlen($digits) >= 4) {
                    $customer->orWhere('phone', 'like', "%{$digits}%")->orWhere('secondary_phone', 'like', "%{$digits}%");
                }
                if (strlen($plate) >= 5) {
                    $customer->orWhere('document', 'like', "%{$plate}%");
                }
            })
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        // "OS 42", "#00042" ou só o número; senão, OS da placa exata
        $numeric = preg_replace('/^(os)?\s*#?\s*/i', '', $query);
        $number = ctype_digit($numeric) ? ltrim($numeric, '0') : '';
        $orders = match (true) {
            $number !== '' && strlen($number) <= 9 => ServiceOrder::whereKey((int) $number),
            strlen($plate) >= 5 => ServiceOrder::whereHas('vehicle', fn (Builder $vehicle) => $vehicle->withTrashed()->where('plate', $plate)),
            default => null,
        };
        $orders = $orders?->with(['customer', 'vehicle'])->latest('id')->limit(self::LIMIT)->get() ?? collect();

        return response()->json(['data' => [
            'vehicles' => $vehicles->map(fn (Vehicle $vehicle) => [
                'id' => $vehicle->id,
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'model_year' => $vehicle->model_year,
                'plate' => $vehicle->plate,
                'customer' => ['id' => $vehicle->customer->id, 'name' => $vehicle->customer->trade_name ?: $vehicle->customer->name],
                'active_order' => ($order = $activeOrders->get($vehicle->id))
                    ? ['id' => $order->id, 'number' => $order->number(), 'status' => $order->status->value, 'status_label' => $order->status->label()]
                    : null,
            ]),
            'customers' => $customers->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->trade_name ?: $customer->name,
                'document' => $customer->document,
                'phone' => $customer->phone,
            ]),
            'orders' => $orders->map(fn (ServiceOrder $order) => [
                'id' => $order->id,
                'number' => $order->number(),
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'customer' => $order->customer->trade_name ?: $order->customer->name,
                'vehicle' => trim($order->vehicle->brand.' '.$order->vehicle->model),
                'plate' => $order->vehicle->plate,
            ]),
        ]]);
    }
}
