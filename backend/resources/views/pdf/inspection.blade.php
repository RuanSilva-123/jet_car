@extends('pdf.layout')

@php
    use App\Models\ServiceOrderInspection;

    $inspection = $order->inspection;
    $checked = collect($inspection?->checklist ?? []);
@endphp

@section('title', 'Vistoria de entrada '.$order->number())

@section('content')
    <table class="doc-head">
        <tr>
            <td>
                <div class="doc-type">Vistoria de entrada</div>
                <div class="doc-number">OS {{ $order->number() }}</div>
                <p class="doc-lead">Estado do veículo ao chegar na oficina.</p>
            </td>
            <td style="width: 58mm; vertical-align: bottom;">
                <table class="doc-meta">
                    <tr><td class="label">Entrada</td><td class="value">{{ $order->created_at->format('d/m/Y H:i') }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @include('pdf.partials.parties')

    @if (! $inspection)
        <div class="block"><p>Vistoria ainda não registrada.</p></div>
    @else
        <table class="section">
            <tr>
                <td style="width: 50%; padding-right: 6mm;">
                    <div class="label">Combustível</div>
                    <p class="gauge">{{ $inspection->fuel_level !== null ? ServiceOrderInspection::FUEL_LABELS[$inspection->fuel_level] : 'Não informado' }}</p>
                </td>
                <td style="width: 50%;">
                    <div class="label">Itens conferidos</div>
                    <p class="checklist">
                        @foreach (ServiceOrderInspection::CHECKLIST as $key => $label)
                            <span class="{{ $checked->contains($key) ? 'yes' : 'no' }}">{{ $checked->contains($key) ? '✓' : '✗' }} {{ $label }}</span>
                        @endforeach
                    </p>
                </td>
            </tr>
        </table>

        <div class="section">
            <h2 class="section-title">Avarias encontradas</h2>
            @if (empty($inspection->damages))
                <p class="muted-text">Nenhuma avaria registrada.</p>
            @else
                <table class="items">
                    <thead>
                        <tr>
                            <th class="num">#</th>
                            <th style="width: 42mm;">Local</th>
                            <th style="width: 38mm;">Tipo</th>
                            <th>Observação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($inspection->damages as $index => $damage)
                            <tr>
                                <td class="num">{{ $index + 1 }}</td>
                                <td class="name">{{ ServiceOrderInspection::DAMAGE_AREAS[$damage['area']] ?? $damage['area'] }}</td>
                                <td>{{ ServiceOrderInspection::DAMAGE_TYPES[$damage['type']] ?? $damage['type'] }}</td>
                                <td>{{ $damage['notes'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if ($inspection->belongings)
            <div class="block">
                <div class="label">Pertences deixados no veículo</div>
                <p>{{ $inspection->belongings }}</p>
            </div>
        @endif

        @if ($inspection->notes)
            <div class="block">
                <div class="label">Observações</div>
                <p>{{ $inspection->notes }}</p>
            </div>
        @endif

        @if ($photos !== [])
            <div class="section">
                <h2 class="section-title">Fotos</h2>
                <table class="photos">
                    @foreach (array_chunk($photos, 3) as $row)
                        <tr>
                            @foreach ($row as $photo)
                                <td>
                                    <img src="{{ $photo['src'] }}" alt="">
                                    @if ($photo['caption'])
                                        <small>{{ $photo['caption'] }}</small>
                                    @endif
                                </td>
                            @endforeach
                            @for ($i = count($row); $i < 3; $i++)
                                <td></td>
                            @endfor
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    @endif
@endsection
