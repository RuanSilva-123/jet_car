@php
    use App\Support\BrFormat;

    $shopDocument = $shop['document']
        ? (strlen($shop['document']) > 11 ? 'CNPJ ' : 'CPF ').BrFormat::document($shop['document'])
        : null;
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        /* dompdf: layout só com tabelas e blocos (sem flex/grid) */
        @page { margin: 30mm 16mm 18mm 16mm; }

        * { box-sizing: border-box; }
        body { margin: 0; color: #27272a; font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; line-height: 1.45; }
        h1, h2, h3, p { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }

        /* Cabeçalho e rodapé repetidos em todas as páginas */
        .page-header { position: fixed; top: -22mm; left: 0; right: 0; height: 17mm; border-bottom: 0.5mm solid #d90429; }
        .page-header td { vertical-align: middle; }
        .page-header .logo { width: 34mm; }
        .page-header .shop { text-align: right; font-size: 7.5pt; line-height: 1.5; color: #71717a; }
        .page-header .shop strong { color: #18181b; font-size: 9pt; }

        .page-footer { position: fixed; bottom: -11mm; left: 0; right: 0; height: 6mm; border-top: 0.25mm solid #e4e4e7; padding-top: 1.5mm; font-size: 7pt; color: #a1a1aa; }
        .page-footer .right { text-align: right; }

        /* Título do documento */
        .doc-head { margin-bottom: 6mm; }
        .doc-type { color: #d90429; font-size: 7.5pt; font-weight: bold; letter-spacing: 1.2pt; text-transform: uppercase; }
        .doc-number { margin-top: 0.5mm; color: #18181b; font-size: 19pt; font-weight: bold; letter-spacing: -0.2pt; }
        .doc-lead { margin-top: 1mm; color: #71717a; font-size: 8.5pt; }
        .doc-meta td { padding: 0.6mm 0; font-size: 8pt; }
        .doc-meta td.label { color: #a1a1aa; padding-right: 4mm; }
        .doc-meta td.value { color: #18181b; font-weight: bold; text-align: right; }

        /* Cliente e veículo */
        .parties { border-top: 0.25mm solid #e4e4e7; border-bottom: 0.25mm solid #e4e4e7; }
        .parties td.party { width: 50%; padding: 3.5mm 0; }
        .parties td.party + td.party { padding-left: 8mm; }
        .label { color: #a1a1aa; font-size: 6.8pt; font-weight: bold; letter-spacing: 0.8pt; text-transform: uppercase; }
        .party strong { display: block; margin-top: 1mm; color: #18181b; font-size: 10pt; }
        .party .line { color: #52525b; font-size: 8.2pt; }

        /* Blocos de texto */
        .block { margin-top: 6mm; }
        .block p { margin-top: 1mm; color: #3f3f46; font-size: 8.6pt; }

        /* Tabelas de itens */
        .section { margin-top: 6mm; }
        .section-title { margin-bottom: 1.5mm; color: #18181b; font-size: 9.5pt; font-weight: bold; }
        .items th { padding: 1.6mm 2mm; border-bottom: 0.3mm solid #18181b; color: #71717a; font-size: 6.8pt; font-weight: bold; letter-spacing: 0.6pt; text-align: left; text-transform: uppercase; }
        .items td { padding: 1.8mm 2mm; border-bottom: 0.2mm solid #ececef; }
        .items th:first-child, .items td:first-child { padding-left: 0; }
        .items th:last-child, .items td:last-child { padding-right: 0; }
        .items .num { width: 7mm; color: #a1a1aa; }
        .items .right { text-align: right; white-space: nowrap; }
        .items .qty { width: 14mm; text-align: center; }
        .items .money { width: 27mm; }
        .items .name { color: #18181b; }
        .items small { display: block; color: #a1a1aa; font-size: 7.5pt; }
        .tbd { color: #a1a1aa; font-style: italic; }
        .done { color: #15803d; font-weight: bold; font-size: 7.8pt; }
        .not-done { color: #a1a1aa; font-size: 7.8pt; }

        /* Fechamento: texto à esquerda, totais à direita */
        .summary { margin-top: 5mm; page-break-inside: avoid; }
        .summary__aside { padding: 1.5mm 10mm 0 0; }
        .summary__aside .label { margin-top: 3.5mm; }
        .summary__aside .label:first-child { margin-top: 0; }
        .summary__aside p { margin-top: 0.8mm; color: #52525b; font-size: 7.8pt; }
        .summary__aside p.strong { color: #27272a; font-size: 8.4pt; }
        .summary__totals { width: 74mm; }
        .totals { width: 100%; }
        .totals td { padding: 1.1mm 0; font-size: 8.6pt; color: #52525b; }
        .totals td.value { text-align: right; white-space: nowrap; color: #27272a; }
        .totals tr.discount td { color: #15803d; }
        .totals tr.grand td { padding-top: 2.2mm; border-top: 0.35mm solid #18181b; color: #18181b; font-size: 10pt; font-weight: bold; }
        .totals tr.grand td.value { font-size: 13pt; }
        .totals-note { margin-top: 1.5mm; color: #a1a1aa; font-size: 7.5pt; text-align: right; }

    </style>
</head>
<body>
    <div class="page-header">
        <table>
            <tr>
                <td class="logo"><img src="{{ $logo }}" style="width: 32mm;" alt="JetCar"></td>
                <td class="shop">
                    <strong>{{ $shop['name'] }}</strong><br>
                    @if ($shopDocument || $shop['phone'])
                        {{ collect([$shopDocument, $shop['phone'] ? BrFormat::phone($shop['phone']) : null])->filter()->implode('  ·  ') }}<br>
                    @endif
                    {{ collect([$shop['address'] ?: null, $shop['email'] ?: null])->filter()->implode('  ·  ') }}
                </td>
            </tr>
        </table>
    </div>

    <div class="page-footer">
        <table>
            <tr>
                <td>@yield('title') · {{ $shop['name'] }}</td>
                <td class="right">Emitido em {{ $generatedAt->format('d/m/Y \à\s H:i') }}</td>
            </tr>
        </table>
    </div>

    @yield('content')
</body>
</html>
