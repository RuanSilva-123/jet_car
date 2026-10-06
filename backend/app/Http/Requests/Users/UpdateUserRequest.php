<?php

namespace App\Http\Requests\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            // Senha em branco na edição = manter a atual
            'password' => $this->filled('password') ? $this->input('password') : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique(User::class, 'email')->ignore($this->route('user')),
            ],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * O master não pode tirar o próprio acesso (evita o painel ficar sem administrador).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->user()->is($this->route('user'))) {
                return;
            }

            if ($this->input('role') !== UserRole::Master->value) {
                $validator->errors()->add('role', 'Você não pode remover o seu próprio perfil de master.');
            }

            if (! $this->boolean('is_active')) {
                $validator->errors()->add('is_active', 'Você não pode desativar a sua própria conta.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return UserRequestMessages::all();
    }
}
