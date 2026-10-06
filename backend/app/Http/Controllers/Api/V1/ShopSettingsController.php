<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\BrazilianDocument;
use App\Support\ShopSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Dados da oficina exibidos nos PDFs. */
class ShopSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => ShopSettings::get()]);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('manage-settings');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'document' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:300'],
            'budget_validity_days' => ['required', 'integer', 'min:1', 'max:365'],
            'warranty_text' => ['nullable', 'string', 'max:1000'],
            'budget_notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'name.required' => 'Informe o nome da oficina.',
            'email.email' => 'Informe um e-mail válido.',
            'budget_validity_days.*' => 'Validade deve ser entre 1 e 365 dias.',
        ]);

        // Guardados só com dígitos/letras; a formatação acontece no PDF
        $data['document'] = BrazilianDocument::normalize($data['document'] ?? '');
        $data['phone'] = preg_replace('/\D/', '', (string) ($data['phone'] ?? ''));

        foreach (['email', 'address', 'warranty_text', 'budget_notes'] as $field) {
            $data[$field] = trim((string) ($data[$field] ?? ''));
        }

        return response()->json(['data' => ShopSettings::save($data)]);
    }
}
