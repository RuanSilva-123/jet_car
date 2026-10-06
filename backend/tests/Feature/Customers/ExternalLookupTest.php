<?php

namespace Tests\Feature\Customers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Proxies da FIPE e do ViaCEP. Nenhuma requisição real sai dos testes.
 */
class ExternalLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.fipe.base_url' => 'https://fipe.test/api/v2',
            'services.viacep.base_url' => 'https://viacep.test/ws',
        ]);

        $this->withHeader('Referer', 'http://localhost')->actingAs(User::factory()->create());
    }

    public function test_guest_cannot_use_lookups(): void
    {
        auth('web')->logout();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/vehicle-catalog/car/brands')->assertUnauthorized();
        $this->getJson('/api/v1/address-lookup/01001000')->assertUnauthorized();
    }

    public function test_brands_are_fetched_from_fipe_and_cached(): void
    {
        Http::fake(['fipe.test/api/v2/cars/brands' => Http::response([['code' => '23', 'name' => 'GM - Chevrolet']])]);

        $this->getJson('/api/v1/vehicle-catalog/car/brands')
            ->assertOk()
            ->assertExactJson(['data' => [['code' => '23', 'name' => 'GM - Chevrolet']]]);

        $this->getJson('/api/v1/vehicle-catalog/car/brands')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_years_of_brand_then_models_of_that_year(): void
    {
        Http::fake([
            'fipe.test/api/v2/motorcycles/brands/80/years' => Http::response([['code' => '2024-1', 'name' => '2024 Gasolina']]),
            'fipe.test/api/v2/motorcycles/brands/80/years/2024-1/models' => Http::response([['code' => 7, 'name' => 'CG 160']]),
        ]);

        $this->getJson('/api/v1/vehicle-catalog/motorcycle/brands/80/years')
            ->assertJsonPath('data.0.code', '2024-1')
            ->assertJsonPath('data.0.name', '2024 Gasolina');

        $this->getJson('/api/v1/vehicle-catalog/motorcycle/brands/80/years/2024-1/models')
            ->assertJsonPath('data.0.code', '7')
            ->assertJsonPath('data.0.name', 'CG 160');
    }

    public function test_zero_km_year_code_is_accepted_and_malformed_year_is_not_routed(): void
    {
        Http::fake(['fipe.test/api/v2/cars/brands/23/years/32000-1/models' => Http::response([])]);

        $this->getJson('/api/v1/vehicle-catalog/car/brands/23/years/32000-1/models')->assertOk();
        $this->getJson('/api/v1/vehicle-catalog/car/brands/23/years/2024/models')->assertNotFound();
    }

    public function test_invalid_vehicle_type_is_not_found(): void
    {
        $this->getJson('/api/v1/vehicle-catalog/boat/brands')->assertNotFound();
    }

    public function test_fipe_failure_returns_503_and_is_not_cached(): void
    {
        Http::fakeSequence('fipe.test/*')
            ->push('Too Many Requests', 429)
            ->push([['code' => '1', 'name' => 'Acura']]);

        $this->getJson('/api/v1/vehicle-catalog/car/brands')
            ->assertStatus(503)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'manualmente'));

        $this->getJson('/api/v1/vehicle-catalog/car/brands')->assertOk()->assertJsonPath('data.0.name', 'Acura');
    }

    public function test_fipe_token_is_sent_when_configured(): void
    {
        config(['services.fipe.token' => 'meu-token']);
        Http::fake(['fipe.test/*' => Http::response([])]);

        $this->getJson('/api/v1/vehicle-catalog/truck/brands')->assertOk();

        Http::assertSent(fn ($request) => $request->hasHeader('X-Subscription-Token', 'meu-token'));
    }

    public function test_zip_code_lookup(): void
    {
        Http::fake(['viacep.test/ws/01001000/json/' => Http::response([
            'cep' => '01001-000', 'logradouro' => 'Praça da Sé', 'complemento' => 'lado ímpar',
            'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP',
        ])]);

        $this->getJson('/api/v1/address-lookup/01001000')
            ->assertOk()
            ->assertJsonPath('data.street', 'Praça da Sé')
            ->assertJsonPath('data.city', 'São Paulo')
            ->assertJsonPath('data.state', 'SP');
    }

    public function test_unknown_zip_code_returns_404(): void
    {
        Http::fake(['viacep.test/*' => Http::response(['erro' => 'true'])]);

        $this->getJson('/api/v1/address-lookup/99999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'CEP não encontrado.');
    }

    public function test_zip_code_service_failure_returns_503(): void
    {
        Http::fake(['viacep.test/*' => Http::response('erro', 500)]);

        $this->getJson('/api/v1/address-lookup/01001000')->assertStatus(503);
    }

    public function test_malformed_zip_code_is_not_routed(): void
    {
        $this->getJson('/api/v1/address-lookup/123')->assertNotFound();
    }
}
