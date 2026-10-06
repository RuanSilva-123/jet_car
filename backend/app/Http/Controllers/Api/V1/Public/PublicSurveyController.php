<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\ServiceOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use App\Support\ShopSettings;
use App\Support\SurveyLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pesquisa de satisfação aberta pelo cliente (link assinado enviado depois da entrega).
 * Uma resposta por OS; não mostra dados pessoais.
 */
class PublicSurveyController extends Controller
{
    public function show(string $token): JsonResponse
    {
        return $this->present(SurveyLink::resolve($token));
    }

    public function answer(Request $request, string $token): JsonResponse
    {
        $order = SurveyLink::resolve($token);

        $data = $request->validate([
            'score' => ['required', 'integer', 'between:0,10'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'score.required' => 'Escolha uma nota de 0 a 10.',
            'score.*' => 'Escolha uma nota de 0 a 10.',
            'comment.max' => 'O comentário pode ter no máximo 1000 caracteres.',
        ]);

        if ($order->status !== ServiceOrderStatus::Delivered) {
            throw ValidationException::withMessages(['score' => 'Esta avaliação não está disponível.']);
        }

        DB::transaction(function () use ($order, $data) {
            // Trava a linha: dois envios simultâneos não gravam duas respostas
            $locked = ServiceOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->survey_answered_at !== null) {
                throw ValidationException::withMessages(['score' => 'Você já avaliou este atendimento. Obrigado!']);
            }

            $comment = trim((string) ($data['comment'] ?? '')) ?: null;
            $locked->forceFill([
                'survey_score' => $data['score'],
                'survey_comment' => $comment,
                'survey_answered_at' => now(),
            ])->save();

            $locked->events()->create([
                'user_id' => null,
                'type' => 'survey_answered',
                'description' => "Cliente avaliou o atendimento: nota {$data['score']}/10.".($comment ? "\n“{$comment}”" : ''),
            ]);
        });

        return $this->present($order->fresh());
    }

    private function present(ServiceOrder $order): JsonResponse
    {
        $order->loadMissing(['customer', 'vehicle']);

        $state = match (true) {
            $order->survey_answered_at !== null => 'answered',
            $order->status === ServiceOrderStatus::Delivered => 'open',
            default => 'unavailable',
        };

        return response()->json(['data' => [
            'state' => $state,
            'shop' => ['name' => ShopSettings::get()['name'], 'phone' => ShopSettings::get()['phone']],
            'order' => [
                'number' => $order->number(),
                'customer_first_name' => Str::of($order->customer->trade_name ?: $order->customer->name)->explode(' ')->first(),
                'vehicle' => trim($order->vehicle->brand.' '.$order->vehicle->model),
                'delivered_at' => $order->delivered_at?->toIso8601String(),
            ],
            'score' => $order->survey_score,
            'comment' => $order->survey_comment,
        ]]);
    }
}
