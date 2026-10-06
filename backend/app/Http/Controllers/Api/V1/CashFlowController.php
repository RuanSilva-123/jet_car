<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Finance\CashFlow;
use App\Support\FinanceSettings;
use App\Support\LocalTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Fluxo de caixa do mês e o saldo inicial do caixa. ?format=csv exporta os dias. */
class CashFlowController extends Controller
{
    public function show(Request $request, CashFlow $cashFlow): JsonResponse|StreamedResponse
    {
        Gate::authorize('manage-finance');

        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'format' => ['nullable', 'in:json,csv'],
        ], ['month.*' => 'Mês inválido.']);

        $month = isset($data['month']) ? Carbon::parse($data['month'].'-01', (string) config('jetcar.timezone')) : LocalTime::now();
        $result = $cashFlow->month($month);

        if (($data['format'] ?? 'json') === 'csv') {
            return response()->streamDownload(function () use ($result) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                $money = fn (int $cents) => number_format($cents / 100, 2, ',', '');
                fputcsv($out, ['Data', 'Entradas', 'Entradas previstas', 'Saídas', 'Saídas previstas', 'Saldo', 'Situação'], ';', '"', '');
                foreach ($result['days'] as $day) {
                    fputcsv($out, [
                        Carbon::parse($day['date'])->format('d/m/Y'),
                        $money($day['in_cents']),
                        $money($day['planned_in_cents']),
                        $money($day['out_cents']),
                        $money($day['planned_out_cents']),
                        $money($day['balance_cents']),
                        $day['projected'] ? 'Previsto' : 'Realizado',
                    ], ';', '"', '');
                }
                fclose($out);
            }, 'fluxo-de-caixa-'.$result['month'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->json(['data' => $result]);
    }

    public function settings(): JsonResponse
    {
        Gate::authorize('manage-finance');

        return response()->json(['data' => FinanceSettings::get()]);
    }

    /** Saldo que havia em caixa/banco numa data: ponto de partida do fluxo de caixa. */
    public function updateSettings(Request $request): JsonResponse
    {
        abort_unless($request->user()->isMaster(), 403, 'Somente o administrador master define o saldo inicial.');

        $data = $request->validate([
            'opening_balance_cents' => ['required', 'integer', 'min:-100000000', 'max:100000000'],
            'opening_date' => ['required', 'date_format:Y-m-d'],
        ], [
            'opening_balance_cents.*' => 'Saldo inválido.',
            'opening_date.*' => 'Informe a data do saldo.',
        ]);

        return response()->json(['data' => FinanceSettings::save($data)]);
    }
}
