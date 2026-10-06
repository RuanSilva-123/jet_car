<?php

namespace Tests\Feature\LaborServices;

use App\Models\LaborService;
use App\Models\User;
use App\Support\SuggestedLaborServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaborServiceManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost');
        $this->admin = User::factory()->create();
    }

    public function test_guest_cannot_access_catalog(): void
    {
        $this->getJson('/api/v1/labor-services')->assertUnauthorized();
        $this->postJson('/api/v1/labor-services', ['name' => 'X'])->assertUnauthorized();
    }

    public function test_any_user_can_create_service_without_price(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/labor-services', [
                'name' => '  Troca  de   amortecedor ',
                'category' => 'suspension',
                'description' => '  Dianteiro ou traseiro  ',
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Troca de amortecedor')
            ->assertJsonPath('data.category_label', 'Suspensão')
            ->assertJsonPath('data.description', 'Dianteiro ou traseiro')
            ->assertJsonMissingPath('data.price');

        $this->assertDatabaseHas('labor_services', ['name' => 'Troca de amortecedor', 'created_by' => $this->admin->id]);
    }

    public function test_validation(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/labor-services', ['name' => '', 'category' => 'foguete', 'is_active' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Informe o nome do serviço.', 'category' => 'Categoria inválida.']);
    }

    public function test_name_is_unique_ignoring_case_but_not_deleted_ones(): void
    {
        LaborService::factory()->create(['name' => 'Troca de correia dentada']);
        $deleted = LaborService::factory()->create(['name' => 'Alinhamento']);
        $deleted->delete();

        $this->actingAs($this->admin);

        $this->postJson('/api/v1/labor-services', ['name' => 'TROCA DE CORREIA DENTADA', 'category' => 'engine', 'is_active' => true])
            ->assertJsonValidationErrors(['name' => 'Já existe um serviço com este nome.']);

        $this->postJson('/api/v1/labor-services', ['name' => 'Alinhamento', 'category' => 'tires', 'is_active' => true])
            ->assertCreated();
    }

    public function test_update_keeps_own_name_and_can_deactivate(): void
    {
        $service = LaborService::factory()->create(['name' => 'Balanceamento', 'category' => 'tires']);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/labor-services/{$service->id}", [
                'name' => 'Balanceamento',
                'category' => 'tires',
                'description' => 'Por roda',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.description', 'Por roda');
    }

    public function test_list_search_and_filters(): void
    {
        LaborService::factory()->create(['name' => 'Troca de pastilhas de freio', 'category' => 'brakes']);
        LaborService::factory()->create(['name' => 'Troca de discos', 'category' => 'brakes', 'description' => 'freio dianteiro']);
        LaborService::factory()->inactive()->create(['name' => 'Retífica', 'category' => 'engine']);

        $this->actingAs($this->admin);

        $this->getJson('/api/v1/labor-services')->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.name', 'Retífica');
        $this->getJson('/api/v1/labor-services?search=FREIO')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/labor-services?category=engine')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/labor-services?status=active')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/labor-services?category=invalida')->assertUnprocessable();
    }

    public function test_only_master_can_delete_and_it_is_soft(): void
    {
        $service = LaborService::factory()->create();

        $this->actingAs($this->admin)->deleteJson("/api/v1/labor-services/{$service->id}")->assertForbidden();

        $this->actingAs(User::factory()->master()->create())
            ->deleteJson("/api/v1/labor-services/{$service->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($service);
        $this->getJson("/api/v1/labor-services/{$service->id}")->assertNotFound();
    }

    public function test_import_suggestions_skips_existing_names_and_is_idempotent(): void
    {
        LaborService::factory()->create(['name' => 'troca de correia dentada', 'category' => 'engine']);
        $total = count(SuggestedLaborServices::all());

        $this->actingAs($this->admin)
            ->postJson('/api/v1/labor-services/suggestions')
            ->assertCreated()
            ->assertJsonPath('data.created', $total - 1);

        $this->postJson('/api/v1/labor-services/suggestions')
            ->assertOk()
            ->assertJsonPath('data.created', 0);

        $this->assertSame($total, LaborService::count());
    }

    public function test_suggested_list_has_no_duplicate_names(): void
    {
        $names = array_map(fn ($item) => mb_strtolower($item['name']), SuggestedLaborServices::all());

        $this->assertSame($names, array_values(array_unique($names)));
    }
}
