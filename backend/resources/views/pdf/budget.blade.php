@extends('pdf.layout')

@php
    use App\Support\BrFormat;
    use App\Support\Money;

    $issuedAt = $order->budget_sent_at ?? now();
    $validUntil = $issuedAt->copy()->addDays($shop['budget_validity_days']);
@endphp

@section('title', 'Orçamento '.$order->number())

@section('totals-aside')
    <div class="label">Como aprovar</div>
    <p class="strong">
        Responda pelo WhatsApp ou fale com a gente{{ $shop['phone'] ? ' pelo '.BrFormat::phone($shop['phone']) : '' }}
        até {{ $validUntil->format('d/m/Y') }}. O serviço começa assim que recebermos a sua aprovação.
    </p>
    @if ($shop['budget_notes'])
        <div class="label">Condições</div>
        <p>{{ $shop['budget_notes'] }}</p>
    @endif
@endsection

@section('content')
    <table class="doc-head">
        <tr>
            <td>
                <div class="doc-type">Orçamento</div>
                <div class="doc-number">OS {{ $order->number() }}</div>
                <p class="doc-lead">Serviços e peças propostos para o seu veículo.</p>
            </td>
            <td style="width: 58mm; vertical-align: bottom;">
                <table class="doc-meta">
                    <tr><td class="label">Data</td><td class="value">{{ $issuedAt->format('d/m/Y') }}</td></tr>
                    <tr><td class="label">Válido até</td><td class="value">{{ $validUntil->format('d/m/Y') }}</td></tr>
                    @if ($order->expected_at)
                        <tr><td class="label">Previsão de entrega</td><td class="value">{{ $order->expected_at->format('d/m/Y') }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @include('pdf.partials.parties')

    @if ($order->complaint)
        <div class="block">
            <div class="label">Relato do cliente</div>
            <p>{{ $order->complaint }}</p>
        </div>
    @endif

    @if ($order->items->isNotEmpty())
        <div class="section">
            <h2 class="section-title">Mão de obra</h2>
            <table class="items">
                <thead>
                    <tr>
                        <th class="num">#</th>
                        <th>Serviço</th>
                        <th class="right money">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->items as $index => $item)
                        <tr>
                            <td class="num">{{ $index + 1 }}</td>
                            <td>
                                <span class="name">{{ $item->name }}</span>
                                @if ($item->notes)
                                    <small>{{ $item->notes }}</small>
                                @endif
                            </td>
                            @if ($item->price_cents === null)
                                <td class="right money tbd">a definir</td>
                            @else
                                <td class="right money">{{ Money::format($item->price_cents) }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @include('pdf.partials.parts-table', ['title' => 'Peças'])

    @include('pdf.partials.totals')
@endsection
