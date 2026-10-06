<?php

namespace Tests\Feature\ServiceOrders;

use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Vistoria de entrada: dados, fotos, assinatura e trava depois de assinada.
 */
class InspectionTest extends TestCase
{
    use RefreshDatabase;

    /** PNG 1×1 transparente. */
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private ServiceOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withHeader('Referer', 'http://localhost');
        $this->actingAs(User::factory()->create());
        $this->order = ServiceOrder::factory()->create();
    }

    private function payload(array $overrides = []): array
    {
        return [
            'fuel_level' => 2,
            'damages' => [['area' => 'front', 'type' => 'scratch', 'notes' => 'Para-choque']],
            'checklist' => ['spare_tire', 'jack'],
            'belongings' => 'Óculos de sol no porta-luvas',
            'notes' => null,
            ...$overrides,
        ];
    }

    public function test_inspection_is_saved_and_logged(): void
    {
        $this->getJson("/api/v1/service-orders/{$this->order->id}/inspection")->assertOk()->assertJsonPath('data', null);

        $this->putJson("/api/v1/service-orders/{$this->order->id}/inspection", $this->payload())
            ->assertOk()
            ->assertJsonPath('data.fuel_level', 2)
            ->assertJsonPath('data.damages.0.area', 'front')
            ->assertJsonPath('data.checklist', ['spare_tire', 'jack'])
            ->assertJsonPath('options.fuel_levels.4', 'Cheio');

        $this->assertDatabaseHas('service_order_events', ['service_order_id' => $this->order->id, 'type' => 'inspection']);
        $this->getJson("/api/v1/service-orders/{$this->order->id}")->assertJsonPath('data.inspection.exists', true);
    }

    public function test_invalid_damage_is_rejected(): void
    {
        $this->putJson("/api/v1/service-orders/{$this->order->id}/inspection", $this->payload(['damages' => [['area' => 'x', 'type' => 'dent']]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('damages.0.area');
    }

    public function test_photos_are_uploaded_and_served_privately(): void
    {
        $response = $this->post("/api/v1/service-orders/{$this->order->id}/inspection/photos", [
            'photo' => UploadedFile::fake()->image('frente.jpg', 800, 600),
            'caption' => 'Frente',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('photos.0.caption', 'Frente');

        $url = $response->json('photos.0.url');
        $this->get($url)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_signature_locks_the_inspection(): void
    {
        $this->post("/api/v1/service-orders/{$this->order->id}/inspection/photos", ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk();
        $photoId = $this->order->photos()->value('id');

        $this->postJson("/api/v1/service-orders/{$this->order->id}/inspection/sign", ['signed_name' => 'Ruan', 'signature' => self::SIGNATURE])
            ->assertUnprocessable(); // sem vistoria preenchida

        $this->putJson("/api/v1/service-orders/{$this->order->id}/inspection", $this->payload())->assertOk();
        $this->postJson("/api/v1/service-orders/{$this->order->id}/inspection/sign", ['signed_name' => 'Ruan Silva', 'signature' => self::SIGNATURE])
            ->assertOk()
            ->assertJsonPath('data.signed_name', 'Ruan Silva')
            ->assertJsonPath('data.signature_url', "/api/v1/service-orders/{$this->order->id}/inspection/signature");

        $this->get("/api/v1/service-orders/{$this->order->id}/inspection/signature")->assertOk();
        $this->get("/api/v1/service-orders/{$this->order->id}/pdf/inspection")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->putJson("/api/v1/service-orders/{$this->order->id}/inspection", $this->payload())->assertUnprocessable();
        $this->deleteJson("/api/v1/service-orders/{$this->order->id}/inspection/photos/{$photoId}")->assertUnprocessable();
        $this->postJson("/api/v1/service-orders/{$this->order->id}/inspection/sign", ['signed_name' => 'Outro', 'signature' => self::SIGNATURE])->assertUnprocessable();
    }

    public function test_signature_must_be_a_png(): void
    {
        $this->putJson("/api/v1/service-orders/{$this->order->id}/inspection", $this->payload())->assertOk();

        $this->postJson("/api/v1/service-orders/{$this->order->id}/inspection/sign", [
            'signed_name' => 'Ruan',
            'signature' => 'data:image/png;base64,'.base64_encode('<script>'),
        ])->assertUnprocessable()->assertJsonValidationErrors('signature');
    }

    public function test_inspection_pdf(): void
    {
        $this->putJson("/api/v1/service-orders/{$this->order->id}/inspection", $this->payload())->assertOk();

        $this->get("/api/v1/service-orders/{$this->order->id}/pdf/inspection")->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
