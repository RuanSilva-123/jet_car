<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportBuilder;
use App\Support\BrFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatórios gerenciais (financeiro: o mecânico não acessa).
 * ?format=csv baixa o mesmo relatório em CSV para o Excel (";" e vírgula decimal, UTF-8 com BOM).
 */
class ReportController extends Controller
{
    public function __invoke(Request $request, string $report, ReportBuilder $builder): JsonResponse|StreamedResponse
    {
        Gate::authorize('manage-finance');

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group' => ['nullable', 'in:day,month'],
            'only_returning' => ['nullable', 'boolean'],
            'format' => ['nullable', 'in:json,csv'],
        ], [
            'from.*' => 'Data inicial inválida.',
            'to.after_or_equal' => 'A data final deve ser depois da inicial.',
            'to.*' => 'Data final inválida.',
        ]);

        $today = now()->timezone((string) config('jetcar.timezone'));
        $result = $builder->build(
            $report,
            $data['from'] ?? $today->copy()->startOfMonth()->toDateString(),
            $data['to'] ?? $today->toDateString(),
            ['group' => $data['group'] ?? 'day', 'only_returning' => (bool) ($data['only_returning'] ?? false)],
        );

        return ($data['format'] ?? 'json') === 'csv'
            ? $this->csv($result)
            : response()->json(['data' => $result]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function csv(array $result): StreamedResponse
    {
        $filename = 'relatorio-'.$result['report'].'-'.$result['from'].'-a-'.$result['to'].'.csv';

        return response()->streamDownload(function () use ($result) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: o Excel abre com acentos corretos

            fputcsv($out, array_column($result['columns'], 'label'), ';', '"', '');
            $rows = $result['totals'] ? [...$result['rows'], $result['totals']] : $result['rows'];
            foreach ($rows as $row) {
                fputcsv($out, array_map(
                    fn (array $column) => $this->cell($row[$column['key']] ?? null, $column['type']),
                    $result['columns'],
                ), ';', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function cell(mixed $value, string $type): string
    {
        if ($value === null) {
            return '';
        }

        return match ($type) {
            'money' => number_format($value / 100, 2, ',', ''),
            'percent' => number_format((float) $value, 1, ',', ''),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? Carbon::parse($value)->format('d/m/Y') : (string) $value,
            'month' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? Carbon::parse($value)->format('m/Y') : (string) $value,
            'bool' => $value ? 'Sim' : 'Não',
            'phone' => BrFormat::phone((string) $value),
            default => (string) $value,
        };
    }
}
