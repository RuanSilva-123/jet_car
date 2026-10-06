<?php

namespace App\Http\Requests\ServiceOrders;

use App\Enums\ServiceOrderStatus;
use App\Models\ServiceOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Abertura (POST) e edição (PUT) da OS.
 *
 * Abertura = entrada do veículo (cliente, veículo, km, relato). Serviços e peças são opcionais:
 * entram depois, conforme o diagnóstico, e os valores só na montagem do orçamento.
 *
 * Na edição só muda o que vier na requisição: a tela de entrada manda os dados do veículo,
 * a de orçamento manda serviços, peças e desconto. Cliente e veículo não mudam.
 */
class SaveServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('service_order');

        return $order
            ? $this->user()->can('update', $order)
            : $this->user()->can('create', ServiceOrder::class);
    }

    protected function prepareForValidation(): void
    {
        $text = fn (mixed $value) => ($value = trim((string) $value)) === '' ? null : $value;
        $merge = [];

        if ($this->has('mileage')) {
            $digits = preg_replace('/\D/', '', (string) $this->input('mileage'));
            $merge['mileage'] = $digits === '' ? null : $digits;
        }
        foreach (['complaint', 'notes', 'expected_at'] as $field) {
            if ($this->has($field)) {
                $merge[$field] = $text($this->input($field));
            }
        }

        // Linhas: só normaliza o que veio (campo ausente = mantém o valor atual da linha)
        if ($this->has('items')) {
            $merge['items'] = collect((array) $this->input('items'))
                ->map(fn ($item) => is_array($item) && array_key_exists('notes', $item) ? [...$item, 'notes' => $text($item['notes'])] : $item)
                ->all();
        }
        if ($this->has('parts')) {
            $merge['parts'] = collect((array) $this->input('parts'))
                ->map(fn ($part) => is_array($part) ? [
                    ...$part,
                    'name' => $text($part['name'] ?? null),
                    'part_number' => $text($part['part_number'] ?? null),
                ] : $part)
                ->all();
        }
        if ($this->has('discount_cents')) {
            $merge['discount_cents'] = $this->input('discount_cents') ?? 0;
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var ServiceOrder|null $order */
        $order = $this->route('service_order');

        $rules = [
            'mileage' => ['sometimes', 'nullable', 'integer', 'between:0,9999999'],
            'complaint' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'expected_at' => ['sometimes', 'nullable', 'date'],

            // Serviços (mão de obra). Valor null = "a definir".
            'items' => ['sometimes', 'array', 'max:50'],
            'items.*' => ['array'],
            'items.*.id' => [
                'nullable', 'integer',
                Rule::exists('service_order_items', 'id')->where('service_order_id', $order?->id ?? 0),
            ],
            // Serviço novo: precisa existir no catálogo e estar ativo
            'items.*.labor_service_id' => [
                'required_without:items.*.id', 'nullable', 'integer',
                Rule::exists('labor_services', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],

            // Peças. Valor unitário null = "a definir".
            'parts' => ['sometimes', 'array', 'max:100'],
            'parts.*' => ['array'],
            'parts.*.id' => [
                'nullable', 'integer',
                Rule::exists('service_order_parts', 'id')->where('service_order_id', $order?->id ?? 0),
            ],
            'parts.*.name' => ['required', 'string', 'max:150'],
            'parts.*.part_number' => ['nullable', 'string', 'max:60'],
            'parts.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'parts.*.unit_price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],

            'discount_cents' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
        ];

        if ($order === null) {
            $rules += [
                'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
                // O veículo precisa ser do cliente escolhido
                'vehicle_id' => [
                    'required', 'integer',
                    Rule::exists('vehicles', 'id')->where('customer_id', (int) $this->input('customer_id'))->whereNull('deleted_at'),
                ],
                'status' => ['sometimes', Rule::in([ServiceOrderStatus::Open->value, ServiceOrderStatus::InProgress->value])],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.required' => 'Selecione o cliente.',
            'customer_id.exists' => 'Cliente não encontrado.',
            'vehicle_id.required' => 'Selecione o veículo.',
            'vehicle_id.exists' => 'Este veículo não pertence ao cliente selecionado.',
            'status.in' => 'Status inicial inválido.',
            'mileage.between' => 'Quilometragem inválida.',
            'expected_at.date' => 'Data de previsão inválida.',
            'items.max' => 'Máximo de 50 serviços por OS.',
            'items.*.id.exists' => 'Serviço não pertence a esta OS.',
            'items.*.labor_service_id.required_without' => 'Selecione o serviço.',
            'items.*.labor_service_id.exists' => 'Serviço inexistente ou inativo no catálogo.',
            'items.*.price_cents.*' => 'Valor da mão de obra inválido.',
            'parts.max' => 'Máximo de 100 peças por OS.',
            'parts.*.id.exists' => 'Peça não pertence a esta OS.',
            'parts.*.name.required' => 'Informe o nome da peça.',
            'parts.*.quantity.required' => 'Informe a quantidade.',
            'parts.*.quantity.gt' => 'Quantidade deve ser maior que zero.',
            'parts.*.quantity.*' => 'Quantidade inválida.',
            'parts.*.unit_price_cents.*' => 'Valor da peça inválido.',
            'discount_cents.*' => 'Desconto inválido.',
        ];
    }
}
