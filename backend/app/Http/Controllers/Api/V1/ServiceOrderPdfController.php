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
use Illuminate\Support\Facades\Storage;

/**
 * PDFs da OS:
 * - budget: orçamento para o cliente aprovar (com campo de assinatura);
 * - report: comprovante do serviço realizado (o que foi feito, peças, garantia, recebimento);
 * - inspection: vistoria de entrada (combustível, avarias, pertences, fotos e assinatura).
 *
 * ?download=1 baixa o arquivo; sem ele o navegador abre para visualizar/imprimir.
 */
class ServiceOrderPdfController extends Controller
{
    public function __invoke(Request $request, ServiceOrder $serviceOrder, string $document): Response
    {
        Gate::authorize('view', $serviceOrder);

        $order = $serviceOrder->load(['customer', 'vehicle', 'items.doneBy', 'parts', 'payments', 'creator', 'inspection']);

        $filename = match ($document) {
            'budget' => 'Orcamento',
            'inspection' => 'Vistoria',
            default => 'Ordem-de-servico',
        }.'-'.$order->number().'.pdf';

        $extra = $document === 'inspection' ? $this->inspectionImages($order) : [];

        $pdf = Pdf::loadView("pdf.{$document}", [
            ...$extra,
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

    /**
     * Fotos e assinatura da vistoria embutidas no PDF (data URI: o dompdf não lê o disco privado).
     *
     * @return array{photos: list<array{src: string, caption: ?string}>, signature: ?string}
     */
    private function inspectionImages(ServiceOrder $order): array
    {
        $disk = Storage::disk('local');
        $dataUri = fn (string $path) => $disk->exists($path)
            ? 'data:'.($disk->mimeType($path) ?: 'image/jpeg').';base64,'.base64_encode($disk->get($path))
            : null;

        $photos = $order->photos()->limit(12)->get()
            ->map(fn ($photo) => ['src' => $dataUri($photo->path), 'caption' => $photo->caption])
            ->filter(fn (array $photo) => $photo['src'] !== null)
            ->values()
            ->all();

        $signaturePath = $order->inspection?->signature_path;

        return ['photos' => $photos, 'signature' => $signaturePath ? $dataUri($signaturePath) : null];
    }
}
