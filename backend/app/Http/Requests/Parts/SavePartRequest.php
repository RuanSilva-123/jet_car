<?php

namespace App\Http\Requests\Parts;

use App\Models\Part;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cadastro/edição da peça. A quantidade em estoque não muda por aqui: só por entrada,
 * ajuste de inventário ou uso na OS (tudo registrado em stock_movements).
 * No cadastro, initial_stock lança a primeira entrada.
 */
class SavePartRequest extends FormRequest
{
    public const UNITS = ['un', 'par', 'jogo', 'kit', 'L', 'ml', 'kg', 'g', 'm', 'cm'];

    public function authorize(): bool
    {
        $part = $this->route('part');

        return $part ? $this->user()->can('update', $part) : $this->user()->can('create', Part::class);
    }

    protected function prepareForValidation(): void
    {
        $text = fn (mixed $value) => ($value = trim((string) $value)) === '' ? null : $value;

        $this->merge([
            'name' => preg_replace('/\s+/', ' ', trim((string) $this->input('name'))),
            'part_number' => ($code = $text($this->input('part_number'))) === null ? null : mb_strtoupper($code),
            'brand' => $text($this->input('brand')),
            'notes' => $text($this->input('notes')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $part = $this->route('part');

        return [
            'name' => ['required', 'string', 'max:150'],
            'part_number' => [
                'nullable', 'string', 'max:60',
                Rule::unique('parts', 'part_number')->ignore($part?->id)->whereNull('deleted_at'),
            ],
            'brand' => ['nullable', 'string', 'max:80'],
            'unit' => ['required', Rule::in(self::UNITS)],
            'cost_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'min_stock' => ['required', 'numeric', 'min:0', 'max:999999'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'initial_stock' => [$part ? 'prohibited' : 'nullable', 'numeric', 'min:0', 'max:999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome da peça.',
            'part_number.unique' => 'Já existe uma peça com este código.',
            'unit.*' => 'Unidade inválida.',
            'cost_cents.*' => 'Custo inválido.',
            'price_cents.*' => 'Preço de venda inválido.',
            'min_stock.*' => 'Estoque mínimo inválido.',
            'initial_stock.*' => 'Estoque inicial inválido.',
        ];
    }
}
