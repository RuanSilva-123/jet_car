<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use App\Support\BrandLogo;
use App\Support\ShopSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * PDFs da OS:
 * - budget: orçamento para o cliente aprovar (com campo de assinatura);
 * - report: comprovante do serviço realizado (o que foi feito, peças, garantia, recebimento).
 *
 * ?download=1 baixa o arquivo; sem ele o navegador abre para visualizar/imprimir.
 */
class ServiceOrderPdfController extends Controller
{
    public function __invoke(Request $request, ServiceOrder $serviceOrder, string $document): Response
    {
        Gate::authorize('view', $serviceOrder);

        $order = $serviceOrder->load(['customer', 'vehicle', 'items.doneBy', 'parts', 'payments', 'creator']);

        $filename = ($document === 'budget' ? 'Orcamento' : 'Ordem-de-servico').'-'.$order->number().'.pdf';

        $pdf = Pdf::loadView("pdf.{$document}", [
            'order' => $order,
            'shop' => ShopSettings::get(),
            'logo' => BrandLogo::dataUri(),
            'generatedAt' => now(),
        ])
            ->setPaper('a4')
            // Embute só os caracteres usados da fonte: arquivo bem menor
            ->setOption(['isFontSubsettingEnabled' => true, 'defaultFont' => 'DejaVu Sans']);

        return $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
    }
}
