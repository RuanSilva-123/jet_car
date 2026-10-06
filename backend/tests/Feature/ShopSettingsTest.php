<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ShopSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'JetCar Mecânica Automotiva',
            'document' => '11.222.333/0001-81',
            'phone' => '(51) 3333-4444',
            'email' => 'contato@jetcar.test',
            'address' => 'Av. Brasil, 100 · Centro · Porto Alegre / RS',
            'budget_validity_days' => 10,
            'warranty_text' => 'Garantia de 90 dias.',
            'budget_notes' => '',
            ...$overrides,
        ];
    }

    public function test_defaults_are_returned_before_saving(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/settings/shop')
            ->assertOk()
            ->assertJsonPath('data.name', 'JetCar Mecânica Automotiva')
            ->assertJsonPath('data.budget_validity_days', 7);
    }

    public function test_master_saves_shop_data_normalized(): void
    {
        $this->actingAs(User::factory()->master()->create())
            ->putJson('/api/v1/settings/shop', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.document', '11222333000181')
            ->assertJsonPath('data.phone', '5133334444')
            ->assertJsonPath('data.budget_validity_days', 10)
            ->assertJsonPath('data.budget_notes', '');

        $this->assertSame('contato@jetcar.test', ShopSettings::get()['email']);
    }

    public function test_admin_cannot_change_shop_data(): void
    {
        $this->actingAs(User::factory()->create())
            ->putJson('/api/v1/settings/shop', $this->payload())
            ->assertForbidden();
    }

    public function test_validation(): void
    {
        $this->actingAs(User::factory()->master()->create())
            ->putJson('/api/v1/settings/shop', $this->payload(['name' => '', 'budget_validity_days' => 0, 'email' => 'x']))
            ->assertJsonValidationErrors(['name', 'budget_validity_days', 'email']);
    }
}
