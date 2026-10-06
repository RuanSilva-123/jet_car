@php
    use App\Support\BrFormat;

    $customer = $order->customer;
    $vehicle = $order->vehicle;
    $address = collect([
        trim(($customer->street ?? '').($customer->number ? ', '.$customer->number : '')),
        $customer->complement,
        $customer->neighborhood,
        trim(($customer->city ?? '').($customer->state ? ' / '.$customer->state : '')),
    ])->filter()->implode(' · ');
    $year = $vehicle->model_year
        ? ($vehicle->manufacture_year && $vehicle->manufacture_year !== $vehicle->model_year ? $vehicle->manufacture_year.'/' : '').$vehicle->model_year
        : null;
@endphp
<table class="parties">
    <tr>
        <td class="party">
            <div class="label">Cliente</div>
            <strong>{{ $customer->name }}</strong>
            @if ($customer->document)
                <div class="line">{{ $customer->person_type->value === 'company' ? 'CNPJ' : 'CPF' }} {{ BrFormat::document($customer->document) }}</div>
            @endif
            <div class="line">{{ BrFormat::phone($customer->phone) }}@if ($customer->email) · {{ $customer->email }}@endif</div>
            @if ($address)
                <div class="line">{{ $address }}</div>
            @endif
        </td>
        <td class="party">
            <div class="label">Veículo</div>
            <strong>{{ $vehicle->brand }} {{ $vehicle->model }}</strong>
            <div class="line">
                {{ collect([
                    $vehicle->plate ? 'Placa '.BrFormat::plate($vehicle->plate) : null,
                    $year,
                    $vehicle->color,
                    $vehicle->fuel?->label(),
                ])->filter()->implode(' · ') }}
            </div>
            @if ($order->mileage !== null)
                <div class="line">{{ BrFormat::mileage($order->mileage) }} na entrada</div>
            @endif
            @if ($vehicle->vin)
                <div class="line">Chassi {{ $vehicle->vin }}</div>
            @endif
        </td>
    </tr>
</table>
