@extends('pdf.layout')

@php
    use App\Support\LocalTime;
    use App\Support\Money;

    $pendingItems = $order->items->where('is_done', false);
    $isFinished = in_array($order->status->value, ['completed', 'delivered'], true);
@endphp

@section('title', 'Ordem de serviço '.$order->number())

@section('totals-aside')
    @if ($order->warrantyOf)
        <div class="label">Garantia</div>
        <p class="strong">Retorno em garantia da OS {{ $order->warrantyOf->number() }}: os serviços marcados como "Garantia" não são cobrados.</p>
    @endif
    @if ($order->budget_approved_at)
        <div class="label">Aprovação</div>
        <p class="strong">
            Orçamento aprovado pelo cliente em {{ LocalTime::format($order->budget_approved_at, 'd/m/Y') }}
            <span style="white-space: nowrap;">({{ Money::format($order->budget_approved_total_cents ?? $order->total_cents) }}).</span>
        </p>
    @endif
    @if ($isFinished && $pendingItems->isNotEmpty())
        <p class="strong">
            {{ $pendingItems->count() === 1 ? '1 serviço do orçamento não foi realizado.' : $pendingItems->count().' serviços do orçamento não foram realizados.' }}
        </p>
    @endif
    @if ($shop['warranty_text'])
        <div class="label">Garantia</div>
        <p>{{ $shop['warranty_text'] }}</p>
    @endif
@endsection

@section('content')
    <table class="doc-head">
        <tr>
            <td>
                <div class="doc-type">Ordem de serviço</div>
                <div class="doc-number">OS {{ $order->number() }}</div>
                <p class="doc-lead">{{ $isFinished ? 'Serviços realizados no seu veículo.' : 'Situação atual dos serviços no seu veículo.' }}</p>
            </td>
            <td style="width: 58mm; vertical-align: bottom;">
                <table class="doc-meta">
                    <tr><td class="label">Situação</td><td class="value">{{ $order->status->label() }}</td></tr>
                    <tr><td class="label">Entrada</td><td class="value">{{ LocalTime::format($order->created_at, 'd/m/Y') }}</td></tr>
                    @if ($order->completed_at)
                        <tr><td class="label">Conclusão</td><td class="value">{{ LocalTime::format($order->completed_at, 'd/m/Y') }}</td></tr>
                    @endif
                    @if ($order->delivered_at)
                        <tr><td class="label">Entrega</td><td class="value">{{ LocalTime::format($order->delivered_at, 'd/m/Y') }}</td></tr>
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
            <h2 class="section-title">{{ $isFinished ? 'Serviços realizados' : 'Serviços' }}</h2>
            <table class="items">
                <thead>
                    <tr>
                        <th class="num">#</th>
                        <th>Serviço</th>
                        <th style="width: 38mm;">Situação</th>
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
                            <td>
                                @if ($item->is_done)
                                    <span class="done">✓ Realizado</span>
                                    @if ($item->done_at)
                                        <small>em {{ LocalTime::format($item->done_at, 'd/m/Y') }}</small>
                                    @endif
                                @else
                                    <span class="not-done">{{ $isFinished ? 'Não realizado' : 'Pendente' }}</span>
                                @endif
                            </td>
                            @if ($item->warranty_of_item_id)
                                <td class="right money done">Garantia</td>
                            @elseif ($item->price_cents === null)
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

    @include('pdf.partials.parts-table', ['title' => $isFinished ? 'Peças utilizadas' : 'Peças'])

    @include('pdf.partials.totals', ['showPayments' => true])

    @if (! empty($pix))
        <table class="pix">
            <tr>
                <td class="pix__qr"><img src="{{ $pix['qr_code'] }}" alt="QR Code Pix"></td>
                <td class="pix__text">
                    <div class="label">Pague com Pix</div>
                    <p class="pix__amount">{{ Money::format($pix['amount_cents']) }}</p>
                    <p>Aponte a câmera do app do banco para o QR Code ou use o Pix copia e cola:</p>
                    <p class="pix__code">{{ $pix['payload'] }}</p>
                    <p class="pix__who">Recebedor: {{ $pix['beneficiary'] }}</p>
                </td>
            </tr>
        </table>
    @endif

    @if ($order->payments->isNotEmpty())
        <div class="section">
            <h2 class="section-title">Pagamentos</h2>
            <table class="items">
                <thead>
                    <tr>
                        <th style="width: 26mm;">Data</th>
                        <th>Forma de pagamento</th>
                        <th class="right money">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->payments as $payment)
                        <tr>
                            <td>{{ $payment->paid_at->format('d/m/Y') }}</td>
                            <td>
                                <span class="name">{{ $payment->method->label() }}{{ $payment->installments > 1 ? ' em '.$payment->installments.'x' : '' }}</span>
                                @if ($payment->notes)
                                    <small>{{ $payment->notes }}</small>
                                @endif
                            </td>
                            <td class="right money">{{ Money::format($payment->amount_cents) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
