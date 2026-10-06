@php use App\Support\Money; @endphp
{{-- Totais à direita; à esquerda, o texto de fechamento de cada documento (@section('totals-aside')) --}}
<table class="summary">
    <tr>
        <td class="summary__aside">@yield('totals-aside')</td>
        <td class="summary__totals">
            <table class="totals">
                <tr>
                    <td>Mão de obra</td>
                    <td class="value">{{ Money::format($order->labor_total_cents) }}</td>
                </tr>
                @if ($order->parts->isNotEmpty())
                    <tr>
                        <td>Peças</td>
                        <td class="value">{{ Money::format($order->parts_total_cents) }}</td>
                    </tr>
                @endif
                @if ($order->discount_cents > 0)
                    <tr class="discount">
                        <td>Desconto</td>
                        <td class="value">− {{ Money::format($order->discount_cents) }}</td>
                    </tr>
                @endif
                <tr class="grand">
                    <td>Total</td>
                    <td class="value">{{ Money::format($order->total_cents) }}</td>
                </tr>
            </table>
            @if ($order->unpricedCount() > 0)
                <p class="totals-note">Itens "a definir" não estão incluídos no total.</p>
            @endif
        </td>
    </tr>
</table>
