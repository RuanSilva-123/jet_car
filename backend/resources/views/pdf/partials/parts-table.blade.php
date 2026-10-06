@php use App\Support\Money; @endphp
@if ($order->parts->isNotEmpty())
    <div class="section">
        <h2 class="section-title">{{ $title }}</h2>
        <table class="items">
            <thead>
                <tr>
                    <th class="num">#</th>
                    <th>Peça</th>
                    <th class="qty">Qtd.</th>
                    <th class="right money">Unitário</th>
                    <th class="right money">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->parts as $index => $part)
                    <tr>
                        <td class="num">{{ $index + 1 }}</td>
                        <td>
                            <span class="name">{{ $part->name }}</span>
                            @if ($part->part_number)
                                <small>Cód. {{ $part->part_number }}</small>
                            @endif
                        </td>
                        <td class="qty">{{ Money::quantity($part->quantity) }}</td>
                        @if ($part->unit_price_cents === null)
                            <td class="right money tbd">a definir</td>
                            <td class="right money tbd">a definir</td>
                        @else
                            <td class="right money">{{ Money::format($part->unit_price_cents) }}</td>
                            <td class="right money">{{ Money::format($part->totalCents()) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
