<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\BackupStatus;
use App\Support\BrazilianDocument;
use App\Support\Pix\PixCharge;
use App\Support\Pix\PixKey;
use App\Support\ShopSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Dados da oficina exibidos nos PDFs. */
class ShopSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->present(ShopSettings::get());
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
            'warranty_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'pix_key_type' => ['nullable', Rule::in(array_keys(PixKey::TYPES))],
            'pix_key' => ['nullable', 'required_with:pix_key_type', 'string', 'max:77'],
            'pix_beneficiary' => ['nullable', 'string', 'max:60'],
            'pix_city' => ['nullable', 'required_with:pix_key', 'string', 'max:60'],
        ], [
            'name.required' => 'Informe o nome da oficina.',
            'email.email' => 'Informe um e-mail válido.',
            'budget_validity_days.*' => 'Validade deve ser entre 1 e 365 dias.',
            'warranty_days.*' => 'Garantia: de 0 a 3650 dias.',
            'pix_key_type.*' => 'Tipo de chave Pix inválido.',
            'pix_key.required_with' => 'Informe a chave Pix.',
            'pix_key.*' => 'Chave Pix inválida.',
            'pix_city.required_with' => 'Informe a cidade (vai no Pix).',
            'pix_city.max' => 'Cidade muito longa.',
        ]);

        // Chave Pix: normalizada conforme o tipo (CPF/CNPJ só dígitos, celular +55..., e-mail minúsculo)
        if (filled($data['pix_key'] ?? null)) {
            $key = PixKey::normalize((string) ($data['pix_key_type'] ?? ''), (string) $data['pix_key']);
            if ($key === null) {
                throw ValidationException::withMessages(['pix_key' => 'Chave Pix inválida para o tipo '.(PixKey::TYPES[$data['pix_key_type'] ?? ''] ?? 'escolhido').'.']);
            }
            $data['pix_key'] = $key;
        } else {
            $data['pix_key_type'] = '';
            $data['pix_key'] = '';
        }
        foreach (['pix_key_type', 'pix_beneficiary', 'pix_city'] as $field) {
            $data[$field] = trim((string) ($data[$field] ?? ''));
        }

        // Guardados só com dígitos/letras; a formatação acontece no PDF
        $data['document'] = BrazilianDocument::normalize($data['document'] ?? '');
        $data['phone'] = preg_replace('/\D/', '', (string) ($data['phone'] ?? ''));

        foreach (['email', 'address', 'warranty_text', 'budget_notes'] as $field) {
            $data[$field] = trim((string) ($data[$field] ?? ''));
        }

        return $this->present(ShopSettings::save($data));
    }

    /** Situação do último backup automático (só o master). */
    public function backup(): JsonResponse
    {
        Gate::authorize('manage-settings');

        return response()->json(['data' => BackupStatus::get()]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function present(array $settings): JsonResponse
    {
        return response()->json([
            'data' => [...$settings, 'pix_ready' => PixCharge::configured($settings)],
            'options' => ['pix_key_types' => PixKey::TYPES],
        ]);
    }
}
