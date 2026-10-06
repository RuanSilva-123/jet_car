<?php

namespace App\Http\Requests\LaborServices;

use App\Enums\ServiceCategory;
use App\Models\LaborService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLaborServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service = $this->route('labor_service');

        return $service
            ? $this->user()->can('update', $service)
            : $this->user()->can('create', LaborService::class);
    }

    protected function prepareForValidation(): void
    {
        $description = trim((string) $this->input('description'));

        $this->merge([
            // Espaços repetidos viram um só: "Troca  de óleo " → "Troca de óleo"
            'name' => preg_replace('/\s+/', ' ', trim((string) $this->input('name'))),
            'description' => $description === '' ? null : $description,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $service = $this->route('labor_service');

        return [
            'name' => [
                'required', 'string', 'max:120',
                function (string $attribute, mixed $value, Closure $fail) use ($service) {
                    if (is_string($value) && LaborService::nameTaken($value, $service?->id)) {
                        $fail('Já existe um serviço com este nome.');
                    }
                },
            ],
            'category' => ['required', Rule::enum(ServiceCategory::class)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome do serviço.',
            'name.max' => 'O nome pode ter no máximo 120 caracteres.',
            'category.required' => 'Selecione a categoria.',
            'category.enum' => 'Categoria inválida.',
            'description.max' => 'A descrição pode ter no máximo 1000 caracteres.',
        ];
    }
}
