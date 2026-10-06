<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost');
        $this->admin = User::factory()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'person_type' => 'individual',
            'name' => 'Mariana Souza',
            'document' => '529.982.247-25',
            'birth_date' => '1990-05-20',
            'phone' => '(11) 98765-4321',
            'phone_is_whatsapp' => true,
            'secondary_phone' => '(11) 3456-7890',
            'email' => ' Mariana@Email.COM ',
            'zip_code' => '01001-000',
            'street' => 'Praça da Sé',
            'number' => '100',
            'neighborhood' => 'Sé',
            'city' => 'São Paulo',
            'state' => 'sp',
            'vehicles' => [$this->vehicle()],
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vehicle(array $overrides = []): array
    {
        return [
            'type' => 'car',
            'brand' => 'GM - Chevrolet',
            'model' => 'ONIX HATCH LT 1.0 12V Flex 5p Mec.',
            'model_year' => 2020,
            'manufacture_year' => 2019,
            'fuel' => 'flex',
            'plate' => 'abc-1d23',
            'color' => 'Prata',
            'mileage' => '45.000',
            'fipe_brand_code' => '23',
            'fipe_model_code' => '5069',
            'fipe_year_code' => '2020-5',
            ...$overrides,
        ];
    }

    // --- acesso -------------------------------------------------------------

    public function test_guest_cannot_access_customers(): void
    {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
        $this->postJson('/api/v1/customers', $this->payload())->assertUnauthorized();
    }

    // --- cadastro -----------------------------------------------------------

    public function test_any_user_can_create_individual_customer_with_vehicle(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/customers', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.document', '52998224725')
            ->assertJsonPath('data.phone', '11987654321')
            ->assertJsonPath('data.secondary_phone', '1134567890')
            ->assertJsonPath('data.email', 'mariana@email.com')
            ->assertJsonPath('data.zip_code', '01001000')
            ->assertJsonPath('data.state', 'SP')
            ->assertJsonPath('data.vehicles.0.plate', 'ABC1D23')
            ->assertJsonPath('data.vehicles.0.mileage', 45000)
            ->assertJsonPath('data.vehicles.0.fuel_label', 'Flex');

        $customer = Customer::findOrFail($response->json('data.id'));
        $this->assertSame($this->admin->id, $customer->created_by);
        $this->assertCount(1, $customer->vehicles);
    }

    public function test_company_accepts_alphanumeric_cnpj_and_drops_birth_date(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/customers', $this->payload([
                'person_type' => 'company',
                'name' => 'Transportes Rápidos LTDA',
                'trade_name' => 'Rápidos',
                'document' => '12.ABC.345/01DE-35',
                'state_registration' => '123.456.789.110',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.document', '12ABC34501DE35')
            ->assertJsonPath('data.trade_name', 'Rápidos')
            ->assertJsonPath('data.birth_date', null);
    }

    public function test_customer_without_vehicles_and_optional_fields(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/customers', [
                'person_type' => 'individual',
                'name' => 'Cliente Rápido',
                'phone' => '11987654321',
                'phone_is_whatsapp' => false,
                'vehicles' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.document', null)
            ->assertJsonCount(0, 'data.vehicles');
    }

    public function test_create_validates_brazilian_fields(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/customers', $this->payload([
                'document' => '111.111.111-11',
                'phone' => '(11) 1234',
                'email' => 'nao-e-email',
                'state' => 'XX',
                'vehicles' => [$this->vehicle([
                    'plate' => 'AB-123',
                    'vin' => 'IOQ12345678901234',
                    'model_year' => 2020,
                    'manufacture_year' => 2015,
                ])],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'document' => 'CPF inválido.',
                'phone' => 'Telefone inválido. Use DDD + número.',
                'email',
                'state' => 'UF inválida.',
                'vehicles.0.plate' => 'Placa inválida. Use ABC1234 ou ABC1D23.',
                'vehicles.0.vin',
                'vehicles.0.manufacture_year',
            ]);
    }

    public function test_cnpj_is_required_format_for_company(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/customers', $this->payload(['person_type' => 'company', 'document' => '529.982.247-25']))
            ->assertJsonValidationErrors(['document' => 'CNPJ inválido.']);
    }

    public function test_document_and_plate_must_be_unique(): void
    {
        $existing = Customer::factory()->create(['document' => '52998224725']);
        Vehicle::factory()->for($existing)->create(['plate' => 'ABC1D23']);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/customers', $this->payload())
            ->assertJsonValidationErrors([
                'document' => 'Já existe um cliente com este documento.',
                'vehicles.0.plate' => 'Esta placa já está cadastrada em outro cliente.',
            ]);
    }

    public function test_same_plate_twice_in_one_request_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/customers', $this->payload([
                'vehicles' => [$this->vehicle(), $this->vehicle(['plate' => 'ABC1D23'])],
            ]))
            ->assertJsonValidationErrors(['vehicles.0.plate' => 'Placa repetida neste cadastro.']);
    }

    public function test_missing_vehicles_key_is_rejected_instead_of_wiping_vehicles(): void
    {
        $customer = Customer::factory()->has(Vehicle::factory())->create();
        $payload = $this->payload(['document' => $customer->document]);
        unset($payload['vehicles']);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/customers/{$customer->id}", $payload)
            ->assertJsonValidationErrors(['vehicles']);

        $this->assertCount(1, $customer->fresh()->vehicles);
    }

    // --- edição -------------------------------------------------------------

    public function test_update_syncs_vehicles(): void
    {
        $customer = Customer::factory()->create();
        $kept = Vehicle::factory()->for($customer)->create(['plate' => 'KEP1A23', 'mileage' => 1000]);
        $removed = Vehicle::factory()->for($customer)->create(['plate' => 'REM1A23']);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/customers/{$customer->id}", $this->payload([
                'document' => $customer->document,
                'vehicles' => [
                    $this->vehicle(['id' => $kept->id, 'plate' => 'KEP1A23', 'mileage' => 2000]),
                    $this->vehicle(['plate' => 'NEW1B23', 'type' => 'motorcycle', 'brand' => 'HONDA', 'model' => 'CG 160']),
                ],
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'data.vehicles');

        $this->assertSame(2000, $kept->fresh()->mileage);
        $this->assertSoftDeleted($removed);
        $this->assertDatabaseHas('vehicles', ['customer_id' => $customer->id, 'plate' => 'NEW1B23', 'type' => 'motorcycle']);
    }

    public function test_cannot_take_vehicle_from_another_customer(): void
    {
        $customer = Customer::factory()->create();
        $foreignVehicle = Vehicle::factory()->create();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/customers/{$customer->id}", $this->payload([
                'document' => $customer->document,
                'vehicles' => [$this->vehicle(['id' => $foreignVehicle->id, 'plate' => $foreignVehicle->plate])],
            ]))
            ->assertJsonValidationErrors(['vehicles.0.id' => 'Veículo não pertence a este cliente.']);

        $this->assertSame($foreignVehicle->customer_id, $foreignVehicle->fresh()->customer_id);
    }

    public function test_switching_to_individual_clears_company_fields(): void
    {
        $customer = Customer::factory()->company()->create(['state_registration' => '123']);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/customers/{$customer->id}", $this->payload(['trade_name' => 'Ignorado']))
            ->assertOk()
            ->assertJsonPath('data.person_type', 'individual')
            ->assertJsonPath('data.trade_name', null)
            ->assertJsonPath('data.state_registration', null);
    }

    // --- listagem e busca ---------------------------------------------------

    public function test_list_includes_vehicles_summary(): void
    {
        Customer::factory()->has(Vehicle::factory()->count(2))->create(['name' => 'Ana']);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.vehicles_count', 2)
            ->assertJsonCount(2, 'data.0.vehicles');
    }

    public function test_search_by_name_document_phone_plate_and_model(): void
    {
        $carlos = Customer::factory()->create(['name' => 'Carlos Lima', 'document' => '11144477735', 'phone' => '21988887777']);
        Vehicle::factory()->for($carlos)->create(['plate' => 'XYZ9H87', 'model' => 'CIVIC EXL 2.0']);
        // Nomes fixos: o faker pt_BR gera "Carlos" com frequência e deixava o teste instável
        Customer::factory()->create(['name' => 'Ana Souza', 'email' => 'ana@example.com']);
        Customer::factory()->create(['name' => 'Bruno Dias', 'email' => 'bruno@example.com']);

        $this->actingAs($this->admin);

        foreach (['carlos', '111.444', '(21) 98888', 'xyz-9h', 'civic'] as $search) {
            $this->getJson('/api/v1/customers?search='.urlencode($search))
                ->assertJsonPath('meta.total', 1, "Busca por '{$search}'")
                ->assertJsonPath('data.0.id', $carlos->id);
        }
    }

    public function test_filter_by_person_type(): void
    {
        Customer::factory()->create();
        Customer::factory()->company()->create();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/customers?person_type=company')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.person_type', 'company');
    }

    // --- exclusão -----------------------------------------------------------

    public function test_admin_cannot_delete_customer(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->admin)->deleteJson("/api/v1/customers/{$customer->id}")->assertForbidden();

        $this->assertNotSoftDeleted($customer);
    }

    public function test_master_soft_deletes_customer_and_frees_plate_and_document(): void
    {
        $customer = Customer::factory()->create(['document' => '52998224725']);
        $vehicle = Vehicle::factory()->for($customer)->create(['plate' => 'ABC1D23']);

        $this->actingAs(User::factory()->master()->create())
            ->deleteJson("/api/v1/customers/{$customer->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($customer);
        $this->assertSoftDeleted($vehicle);
        $this->getJson("/api/v1/customers/{$customer->id}")->assertNotFound();

        // Documento e placa podem ser usados num novo cadastro
        $this->postJson('/api/v1/customers', $this->payload())->assertCreated();
    }
}
