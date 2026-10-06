<?php

namespace App\Http\Requests\Customers;

use App\Enums\FuelType;
use App\Enums\PersonType;
use App\Enums\VehicleType;
use App\Models\Customer;
use App\Rules\ValidDocument;
use App\Support\BrazilianDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cadastro e edição de cliente com seus veículos (mesmo formulário).
 * Os campos chegam formatados do painel e são normalizados aqui antes da validação.
 */
class SaveCustomerRequest extends FormRequest
{
    public const STATES = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    /** Celular (DDD + 9 + 8 dígitos) ou fixo (DDD + 8 dígitos começando em 2–8). */
    private const PHONE_REGEX = '/^[1-9]{2}(9\d{8}|[2-8]\d{7})$/';

    /** Placa antiga (ABC1234) ou Mercosul (ABC1D23). */
    private const PLATE_REGEX = '/^[A-Z]{3}\d[A-Z0-9]\d{2}$/';

    /** Chassi: 17 caracteres, sem I, O e Q. */
    private const VIN_REGEX = '/^[A-HJ-NPR-Z0-9]{17}$/';

    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer
            ? $this->user()->can('update', $customer)
            : $this->user()->can('create', Customer::class);
    }

    protected function prepareForValidation(): void
    {
        $digits = fn (mixed $value) => $this->blankToNull(preg_replace('/\D/', '', (string) $value));
        $text = fn (mixed $value) => $this->blankToNull(trim((string) $value));
        $upper = fn (mixed $value) => $this->blankToNull(preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $value)));

        $vehicles = collect($this->input('vehicles', []))
            ->map(fn ($vehicle) => is_array($vehicle) ? [
                ...$vehicle,
                'brand' => $text($vehicle['brand'] ?? null),
                'model' => $text($vehicle['model'] ?? null),
                'plate' => $upper($vehicle['plate'] ?? null),
                'color' => $text($vehicle['color'] ?? null),
                'vin' => $upper($vehicle['vin'] ?? null),
                'renavam' => $digits($vehicle['renavam'] ?? null),
                'mileage' => $digits($vehicle['mileage'] ?? null),
                'notes' => $text($vehicle['notes'] ?? null),
            ] : $vehicle)
            ->all();

        $this->merge([
            'name' => $text($this->input('name')),
            'trade_name' => $text($this->input('trade_name')),
            'document' => $this->blankToNull(BrazilianDocument::normalize($this->input('document'))),
            'state_registration' => $text($this->input('state_registration')),
            'phone' => $digits($this->input('phone')),
            'secondary_phone' => $digits($this->input('secondary_phone')),
            'email' => $this->blankToNull(mb_strtolower(trim((string) $this->input('email')))),
            'zip_code' => $digits($this->input('zip_code')),
            'street' => $text($this->input('street')),
            'number' => $text($this->input('number')),
            'complement' => $text($this->input('complement')),
            'neighborhood' => $text($this->input('neighborhood')),
            'city' => $text($this->input('city')),
            'state' => $this->blankToNull(mb_strtoupper(trim((string) $this->input('state')))),
            'notes' => $text($this->input('notes')),
        ]);

        // Sem a chave 'vehicles' a validação falha (present) em vez de sincronizar uma lista vazia
        // e apagar os veículos do cliente
        if ($this->has('vehicles')) {
            $this->merge(['vehicles' => $vehicles]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $customer = $this->route('customer');
        $personType = PersonType::tryFrom((string) $this->input('person_type'));
        $nextYear = (int) date('Y') + 1;

        $rules = [
            'person_type' => ['required', Rule::enum(PersonType::class)],
            'name' => ['required', 'string', 'max:150'],
            'trade_name' => ['nullable', 'string', 'max:150'],
            'document' => [
                'nullable', 'string', new ValidDocument($personType),
                Rule::unique(Customer::class, 'document')->ignore($customer)->whereNull('deleted_at'),
            ],
            'state_registration' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['nullable', 'date', 'after:1900-01-01', 'before:today'],

            'phone' => ['required', 'regex:'.self::PHONE_REGEX],
            'phone_is_whatsapp' => ['required', 'boolean'],
            'secondary_phone' => ['nullable', 'regex:'.self::PHONE_REGEX],
            'email' => ['nullable', 'email', 'max:255'],

            'zip_code' => ['nullable', 'digits:8'],
            'street' => ['nullable', 'string', 'max:150'],
            'number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:100'],
            'neighborhood' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::in(self::STATES)],
            'notes' => ['nullable', 'string', 'max:2000'],

            'vehicles' => ['present', 'array', 'max:20'],
            'vehicles.*' => ['array'],
            'vehicles.*.id' => [
                'nullable', 'integer',
                // Só aceita veículos que já pertencem a este cliente
                Rule::exists('vehicles', 'id')->where('customer_id', $customer?->id ?? 0)->whereNull('deleted_at'),
            ],
            'vehicles.*.type' => ['required', Rule::enum(VehicleType::class)],
            'vehicles.*.brand' => ['required', 'string', 'max:80'],
            'vehicles.*.model' => ['required', 'string', 'max:150'],
            'vehicles.*.model_year' => ['nullable', 'integer', 'between:1900,'.$nextYear],
            'vehicles.*.manufacture_year' => ['nullable', 'integer', 'between:1900,'.$nextYear],
            'vehicles.*.fuel' => ['nullable', Rule::enum(FuelType::class)],
            'vehicles.*.plate' => ['nullable', 'regex:'.self::PLATE_REGEX, 'distinct'],
            'vehicles.*.color' => ['nullable', 'string', 'max:40'],
            'vehicles.*.mileage' => ['nullable', 'integer', 'between:0,9999999'],
            'vehicles.*.vin' => ['nullable', 'regex:'.self::VIN_REGEX],
            'vehicles.*.renavam' => ['nullable', 'digits_between:9,11'],
            'vehicles.*.fipe_brand_code' => ['nullable', 'string', 'max:10'],
            'vehicles.*.fipe_model_code' => ['nullable', 'string', 'max:10'],
            'vehicles.*.fipe_year_code' => ['nullable', 'string', 'max:10'],
            'vehicles.*.notes' => ['nullable', 'string', 'max:1000'],
        ];

        // Regras que dependem de cada veículo. Uma chave explícita (vehicles.0.plate) SUBSTITUI
        // a curinga (vehicles.*.plate), por isso as regras base são repetidas aqui.
        foreach ((array) $this->input('vehicles', []) as $index => $vehicle) {
            $vehicle = is_array($vehicle) ? $vehicle : [];

            // Placa única entre veículos ativos (ignorando o próprio veículo na edição)
            $rules["vehicles.{$index}.plate"] = [
                ...$rules['vehicles.*.plate'],
                Rule::unique('vehicles', 'plate')->ignore($vehicle['id'] ?? null)->whereNull('deleted_at'),
            ];

            // Ano de fabricação: igual ao do modelo ou até 1 ano antes
            if (is_numeric($vehicle['model_year'] ?? null)) {
                $modelYear = (int) $vehicle['model_year'];
                $rules["vehicles.{$index}.manufacture_year"] = [
                    ...$rules['vehicles.*.manufacture_year'],
                    'between:'.($modelYear - 1).','.$modelYear,
                ];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'person_type.required' => 'Selecione o tipo de cliente.',
            'name.required' => $this->input('person_type') === 'company' ? 'Informe a razão social.' : 'Informe o nome.',
            'name.max' => 'Máximo de 150 caracteres.',
            'document.unique' => 'Já existe um cliente com este documento.',
            'birth_date.before' => 'A data de nascimento deve ser anterior a hoje.',
            'birth_date.after' => 'Data de nascimento inválida.',
            'birth_date.date' => 'Data de nascimento inválida.',
            'phone.required' => 'Informe o telefone de contato.',
            'phone.regex' => 'Telefone inválido. Use DDD + número.',
            'secondary_phone.regex' => 'Telefone inválido. Use DDD + número.',
            'email.email' => 'Informe um e-mail válido.',
            'zip_code.digits' => 'CEP deve ter 8 dígitos.',
            'state.in' => 'UF inválida.',
            'vehicles.present' => 'Envie a lista de veículos (pode ser vazia).',
            'vehicles.max' => 'Máximo de 20 veículos por cliente.',
            'vehicles.*.id.exists' => 'Veículo não pertence a este cliente.',
            'vehicles.*.type.required' => 'Selecione o tipo do veículo.',
            'vehicles.*.brand.required' => 'Informe a marca.',
            'vehicles.*.model.required' => 'Informe o modelo.',
            'vehicles.*.model_year.between' => 'Ano do modelo inválido.',
            'vehicles.*.manufacture_year.between' => 'Ano de fabricação deve ser igual ao do modelo ou 1 ano antes.',
            'vehicles.*.plate.regex' => 'Placa inválida. Use ABC1234 ou ABC1D23.',
            'vehicles.*.plate.distinct' => 'Placa repetida neste cadastro.',
            'vehicles.*.plate.unique' => 'Esta placa já está cadastrada em outro cliente.',
            'vehicles.*.mileage.between' => 'Quilometragem inválida.',
            'vehicles.*.vin.regex' => 'Chassi inválido (17 caracteres, sem I, O e Q).',
            'vehicles.*.renavam.digits_between' => 'Renavam deve ter de 9 a 11 dígitos.',
        ];
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
