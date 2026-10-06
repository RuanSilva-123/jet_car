<?php

namespace Tests\Feature\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost');
        $this->master = User::factory()->master()->create(['name' => 'Master']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return [
            'name' => 'João Mecânico',
            'email' => 'joao@jetcar.test',
            'role' => 'admin',
            'is_active' => true,
            'password' => 'senha1234',
            'password_confirmation' => 'senha1234',
            ...$overrides,
        ];
    }

    // --- acesso -------------------------------------------------------------

    public function test_guest_cannot_access_users(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
        $this->postJson('/api/v1/users', $this->validPayload())->assertUnauthorized();
    }

    public function test_admin_cannot_manage_users(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->getJson('/api/v1/users')->assertForbidden();
        $this->getJson("/api/v1/users/{$this->master->id}")->assertForbidden();
        $this->postJson('/api/v1/users', $this->validPayload())->assertForbidden();
        $this->putJson("/api/v1/users/{$admin->id}", $this->validPayload(['role' => 'master']))->assertForbidden();
        $this->deleteJson("/api/v1/users/{$this->master->id}")->assertForbidden();

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    // --- listagem -----------------------------------------------------------

    public function test_master_lists_users_paginated_and_sorted_by_name(): void
    {
        User::factory()->create(['name' => 'Bruno']);
        User::factory()->create(['name' => 'Ana']);

        $this->actingAs($this->master)
            ->getJson('/api/v1/users?per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('data.0.name', 'Ana')
            ->assertJsonMissingPath('data.0.password');
    }

    public function test_master_can_search_by_name_or_email_case_insensitive(): void
    {
        User::factory()->create(['name' => 'Carlos Lima', 'email' => 'carlos@jetcar.test']);
        User::factory()->create(['name' => 'Fernanda', 'email' => 'fe@oficina.test']);

        $this->actingAs($this->master);

        $this->getJson('/api/v1/users?search=CARLOS')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Carlos Lima');
        $this->getJson('/api/v1/users?search=oficina')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Fernanda');
    }

    public function test_master_can_filter_by_status(): void
    {
        User::factory()->inactive()->create();

        $this->actingAs($this->master);

        $this->getJson('/api/v1/users?status=inactive')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/users?status=active')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/users?status=qualquer')->assertUnprocessable();
    }

    // --- cadastro -----------------------------------------------------------

    public function test_master_can_create_user(): void
    {
        $this->actingAs($this->master)
            ->postJson('/api/v1/users', $this->validPayload(['email' => '  Joao@JetCar.TEST ']))
            ->assertCreated()
            ->assertJsonPath('data.email', 'joao@jetcar.test')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.is_active', true);

        $user = User::where('email', 'joao@jetcar.test')->sole();
        $this->assertTrue(Hash::check('senha1234', $user->password));
    }

    public function test_created_user_can_login(): void
    {
        $this->actingAs($this->master)->postJson('/api/v1/users', $this->validPayload())->assertCreated();
        auth('web')->logout();

        $this->postJson('/api/v1/auth/login', ['email' => 'joao@jetcar.test', 'password' => 'senha1234'])->assertOk();
    }

    public function test_create_validates_input(): void
    {
        User::factory()->create(['email' => 'existe@jetcar.test']);

        $this->actingAs($this->master)
            ->postJson('/api/v1/users', [
                'name' => '',
                'email' => 'EXISTE@jetcar.test',
                'role' => 'dono',
                'is_active' => true,
                'password' => 'curta',
                'password_confirmation' => 'diferente',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name' => 'Informe o nome.',
                'email' => 'Já existe um usuário com este e-mail.',
                'role' => 'Perfil inválido.',
                'password',
            ]);
    }

    public function test_password_requires_letters_and_numbers(): void
    {
        $this->actingAs($this->master)
            ->postJson('/api/v1/users', $this->validPayload(['password' => 'somenteletras', 'password_confirmation' => 'somenteletras']))
            ->assertJsonValidationErrors(['password' => 'A senha deve conter pelo menos um número.']);
    }

    // --- edição -------------------------------------------------------------

    public function test_master_can_update_user_without_changing_password(): void
    {
        $user = User::factory()->create(['password' => 'antiga123']);

        $this->actingAs($this->master)
            ->putJson("/api/v1/users/{$user->id}", $this->validPayload([
                'name' => 'Nome Novo',
                'is_active' => false,
                'password' => '',
                'password_confirmation' => '',
            ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Nome Novo')
            ->assertJsonPath('data.is_active', false);

        $this->assertTrue(Hash::check('antiga123', $user->fresh()->password));
    }

    public function test_master_can_reset_user_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->master)
            ->putJson("/api/v1/users/{$user->id}", $this->validPayload(['password' => 'nova12345', 'password_confirmation' => 'nova12345']))
            ->assertOk();

        $this->assertTrue(Hash::check('nova12345', $user->fresh()->password));
    }

    public function test_update_keeps_own_email_unique_check(): void
    {
        $user = User::factory()->create(['email' => 'mesmo@jetcar.test']);

        $this->actingAs($this->master)
            ->putJson("/api/v1/users/{$user->id}", $this->validPayload(['email' => 'mesmo@jetcar.test', 'password' => null]))
            ->assertOk();
    }

    public function test_master_cannot_remove_own_access(): void
    {
        $this->actingAs($this->master)
            ->putJson("/api/v1/users/{$this->master->id}", $this->validPayload([
                'email' => $this->master->email,
                'role' => 'admin',
                'is_active' => false,
                'password' => null,
            ]))
            ->assertJsonValidationErrors([
                'role' => 'Você não pode remover o seu próprio perfil de master.',
                'is_active' => 'Você não pode desativar a sua própria conta.',
            ]);

        $this->assertTrue($this->master->fresh()->isMaster());
        $this->assertTrue($this->master->fresh()->is_active);
    }

    // --- exclusão -----------------------------------------------------------

    public function test_master_can_delete_another_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->master)->deleteJson("/api/v1/users/{$user->id}")->assertNoContent();

        $this->assertModelMissing($user);
    }

    public function test_master_cannot_delete_own_account(): void
    {
        $this->actingAs($this->master)
            ->deleteJson("/api/v1/users/{$this->master->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Você não pode excluir a própria conta.');

        $this->assertModelExists($this->master);
    }

    public function test_deleted_user_loses_access(): void
    {
        $user = User::factory()->create();
        $this->actingAs($this->master)->deleteJson("/api/v1/users/{$user->id}")->assertNoContent();

        // Descarta os guards em memória (o teste reaproveita a mesma aplicação entre requisições)
        $this->app['auth']->forgetGuards();

        // Sessão real do usuário excluído: o guard só guarda o ID e busca no banco a cada requisição
        $this->withSession([auth('web')->getName() => $user->id])
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }
}
