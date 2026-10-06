<?php

namespace Tests\Feature\ServiceOrders;

use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Vistoria de entrada: dados, fotos e PDF.
 */
class InspectionTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_inspection_stays_editable_and_has_no_signature(): void
    {
        $this->putJson("/api/v1/service-orders/{$this->order->id}/inspection", $this->payload())->assertOk();
        $this->post("/api/v1/service-orders/{$this->order->id}/inspection/photos", ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk();
        $photoId = $this->order->photos()->value('id');

        $this->putJson("/api/v1/service-orders/{$this->order->id}/inspection", $this->payload(['fuel_level' => 4]))
            ->assertOk()
            ->assertJsonPath('data.fuel_level', 4)
            ->assertJsonMissingPath('data.signed_at');
        $this->deleteJson("/api/v1/service-orders/{$this->order->id}/inspection/photos/{$photoId}")->assertOk()->assertJsonCount(0, 'photos');

        $this->postJson("/api/v1/service-orders/{$this->order->id}/inspection/sign", ['signed_name' => 'Ruan'])->assertNotFound();
    }

    public function test_inspection_pdf(): void
    {
        $this->putJson("/api/v1/service-orders/{$this->order->id}/inspection", $this->payload())->assertOk();
        $this->post("/api/v1/service-orders/{$this->order->id}/inspection/photos", ['photo' => UploadedFile::fake()->image('frente.jpg')], ['Accept' => 'application/json'])->assertOk();

        $this->get("/api/v1/service-orders/{$this->order->id}/pdf/inspection")->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
