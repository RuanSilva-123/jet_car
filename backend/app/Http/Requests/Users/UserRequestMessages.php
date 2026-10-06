<?php

namespace App\Http\Requests\Users;

/**
 * Mensagens de validação em português compartilhadas pelo cadastro e pela edição.
 */
final class UserRequestMessages
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'name.required' => 'Informe o nome.',
            'name.max' => 'O nome pode ter no máximo 120 caracteres.',
            'email.required' => 'Informe o e-mail.',
            'email.email' => 'Informe um e-mail válido.',
            'email.max' => 'O e-mail pode ter no máximo 255 caracteres.',
            'email.unique' => 'Já existe um usuário com este e-mail.',
            'role.required' => 'Selecione o perfil.',
            'role.enum' => 'Perfil inválido.',
            'is_active.required' => 'Informe o status.',
            'is_active.boolean' => 'Status inválido.',
            'password.required' => 'Informe a senha.',
            'password.confirmed' => 'A confirmação não confere com a senha.',
            'password.min' => 'A senha deve ter pelo menos :min caracteres.',
            'password.letters' => 'A senha deve conter pelo menos uma letra.',
            'password.numbers' => 'A senha deve conter pelo menos um número.',
        ];
    }
}
