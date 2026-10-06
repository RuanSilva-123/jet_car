<?php

namespace Tests\Feature\Customers;

use App\Models\User;
use App\Services\PlateLookup\PlateDataNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlateLookupTest extends TestCase
{
    use RefreshDatabase;

    /** Exemplo da documentação da API Placas (mesmo formato revendido por outros provedores). */
    private const SAMPLE = [
        'MARCA' => 'VW',
        'MODELO' => 'CROSSFOX',
        'SUBMODELO' => 'CROSSFOX',
        'VERSAO' => 'CROSSFOX',
        'ano' => '2007',
        'anoModelo' => '2007',
        'chassi' => '*****10137',
        'cor' => 'Prata',
        'data' => '20/07/2022 15:10:09',
        'extra' => [
            'ano_fabricacao' => '2007',
            'ano_modelo' => '2007',
            'combustivel' => 'Alcool / Gasolina',
            'tipo_doc_prop' => 'Fisica',
            'tipo_veiculo' => 'Automovel',
            'uf' => 'RS',
        ],
        'fipe' => ['dados' => [
            ['ano_modelo' => '2007', 'codigo_marca' => 59, 'codigo_modelo' => '1111', 'combustivel' => 'Gasolina', 'score' => 40,
                'texto_marca' => 'VW - VolksWagen', 'texto_modelo' => 'CROSSFOX outro'],
            ['ano_modelo' => '2007', 'codigo_marca' => 59, 'codigo_modelo' => '2368', 'combustivel' => 'Gasolina', 'score' => 101,
                'texto_marca' => 'VW - VolksWagen', 'texto_modelo' => 'CROSSFOX 1.6 Mi Total Flex 8V 5p'],
        ]],
        'municipio' => 'São Leopoldo',
        'placa' => 'INT8C36',
        'uf' => 'RS',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.plate_lookup.driver' => 'apibrasil',
            'services.plate_lookup.apibrasil.base_url' => 'https://apibrasil.test/api/v2',
            'services.plate_lookup.apibrasil.bearer_token' => 'bearer-teste',
            'services.plate_lookup.apibrasil.device_token' => 'device-teste',
            'services.plate_lookup.apiplacas.base_url' => 'https://apiplacas.test',
            'services.plate_lookup.apiplacas.token' => null,
        ]);

        $this->withHeader('Referer', 'http://localhost')->actingAs(User::factory()->create());
    }

    // --- normalização --------------------------------------------------------

    public function test_normalizer_uses_best_fipe_match_and_hides_owner_data(): void
    {
        $data = (new PlateDataNormalizer)->normalize('INT8C36', self::SAMPLE);

        $this->assertSame([
            'plate' => 'INT8C36',
            'type' => 'car',
            'brand' => 'VW - VolksWagen',
            'model' => 'CROSSFOX 1.6 Mi Total Flex 8V 5p',
            'model_year' => 2007,
            'manufacture_year' => 2007,
            'fuel' => 'flex',
            'color' => 'Prata',
            'city' => 'São Leopoldo',
            'state' => 'RS',
            'fipe' => ['brand_code' => '59', 'model_code' => '2368', 'model_year' => 2007, 'fuel' => 'Gasolina'],
        ], $data);
        $this->assertArrayNotHasKey('tipo_doc_prop', $data);
    }

    public function test_normalizer_unwraps_envelopes_and_ignores_key_case(): void
    {
        $data = (new PlateDataNormalizer)->normalize('ABC1D23', [
            'error' => false,
            'response' => ['marca' => 'HONDA', 'modelo' => 'CG 160 TITAN', 'anomodelo' => 2024, 'COR' => 'VERMELHA',
                'extra' => ['tipo_veiculo' => 'Motocicleta', 'combustivel' => 'Gasolina']],
        ]);

        $this->assertSame('HONDA', $data['brand']);
        $this->assertSame('CG 160 TITAN', $data['model']);
        $this->assertSame(2024, $data['model_year']);
        $this->assertSame('motorcycle', $data['type']);
        $this->assertSame('gasoline', $data['fuel']);
        $this->assertSame('Vermelha', $data['color']);
        $this->assertNull($data['fipe']);
    }

    public function test_normalizer_returns_null_without_brand_and_model(): void
    {
        $this->assertNull((new PlateDataNormalizer)->normalize('ABC1D23', ['mensagemRetorno' => 'Sem dados']));
    }

    // --- APIBrasil ------------------------------------------------------------

    public function test_status_reports_configuration(): void
    {
        $this->getJson('/api/v1/plate-lookup')->assertExactJson(['data' => ['enabled' => true, 'provider' => 'APIBrasil']]);

        config(['services.plate_lookup.apibrasil.device_token' => null]);
        $this->getJson('/api/v1/plate-lookup')->assertJsonPath('data.enabled', false);

        $this->getJson('/api/v1/plate-lookup/ABC1D23')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_apibrasil_lookup_sends_tokens_and_caches_result(): void
    {
        Http::fake(['apibrasil.test/api/v2/vehicles/dados' => Http::response(['error' => false, 'response' => self::SAMPLE])]);

        $this->getJson('/api/v1/plate-lookup/int8c36')
            ->assertOk()
            ->assertJsonPath('data.plate', 'INT8C36')
            ->assertJsonPath('data.fipe.model_code', '2368');

        $this->getJson('/api/v1/plate-lookup/INT8C36')->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['placa'] === 'INT8C36'
            && $request->hasHeader('Authorization', 'Bearer bearer-teste')
            && $request->hasHeader('DeviceToken', 'device-teste'));
    }

    public function test_apibrasil_not_found_returns_404_and_is_not_cached(): void
    {
        Http::fakeSequence('apibrasil.test/*')
            ->push(['error' => true, 'message' => 'Placa não encontrada'])
            ->push(['error' => false, 'response' => self::SAMPLE]);

        $this->getJson('/api/v1/plate-lookup/INT8C36')->assertNotFound();
        $this->getJson('/api/v1/plate-lookup/INT8C36')->assertOk();
    }

    public function test_apibrasil_daily_limit_returns_429(): void
    {
        Http::fake(['apibrasil.test/*' => Http::response(['error' => true, 'message' => 'Limite diário excedido'], 200)]);

        $this->getJson('/api/v1/plate-lookup/INT8C36')
            ->assertTooManyRequests()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'manualmente'));
    }

    public function test_success_message_mentioning_limit_is_not_treated_as_error(): void
    {
        Http::fake(['apibrasil.test/*' => Http::response(['error' => false, 'message' => 'Limite restante: 99', 'response' => self::SAMPLE])]);

        $this->getJson('/api/v1/plate-lookup/INT8C36')->assertOk();
    }

    public function test_provider_failure_returns_503(): void
    {
        Http::fake(['apibrasil.test/*' => Http::response('erro', 500)]);

        $this->getJson('/api/v1/plate-lookup/INT8C36')->assertStatus(503);
    }

    // --- API Placas -------------------------------------------------------------

    public function test_apiplacas_provider(): void
    {
        config(['services.plate_lookup.driver' => 'apiplacas', 'services.plate_lookup.apiplacas.token' => 'tok']);
        Http::fake([
            'apiplacas.test/consulta/INT8C36/tok' => Http::response(self::SAMPLE),
            'apiplacas.test/consulta/ZZZ9Z99/tok' => Http::response(['message' => 'Sem resultados!'], 406),
            'apiplacas.test/consulta/AAA1A11/tok' => Http::response(['message' => 'Limite'], 429),
        ]);

        $this->getJson('/api/v1/plate-lookup')->assertJsonPath('data.provider', 'API Placas');
        $this->getJson('/api/v1/plate-lookup/INT8C36')->assertOk()->assertJsonPath('data.model_year', 2007);
        $this->getJson('/api/v1/plate-lookup/ZZZ9Z99')->assertNotFound();
        $this->getJson('/api/v1/plate-lookup/AAA1A11')->assertTooManyRequests();
    }

    public function test_invalid_plate_format_is_not_routed(): void
    {
        $this->getJson('/api/v1/plate-lookup/AB12345')->assertNotFound();
        Http::assertNothingSent();
    }
}
