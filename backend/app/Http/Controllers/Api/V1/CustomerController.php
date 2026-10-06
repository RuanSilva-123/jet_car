<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Customers\SaveCustomer;
use App\Enums\PersonType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\SaveCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Customer::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'person_type' => ['nullable', Rule::enum(PersonType::class)],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $customers = Customer::query()
            ->with('vehicles:id,customer_id,type,brand,model,model_year,plate')
            ->withCount('vehicles')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this->applySearch($query, $search))
            ->when($filters['person_type'] ?? null, fn (Builder $query, string $type) => $query->where('person_type', $type))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();

        return CustomerResource::collection($customers);
    }

    public function store(SaveCustomerRequest $request, SaveCustomer $saveCustomer): CustomerResource
    {
        $customer = $saveCustomer->handle($request->validated(), actor: $request->user());

        return new CustomerResource($customer);
    }

    public function show(Customer $customer): CustomerResource
    {
        Gate::authorize('view', $customer);

        return new CustomerResource($customer->load('vehicles'));
    }

    public function update(SaveCustomerRequest $request, Customer $customer, SaveCustomer $saveCustomer): CustomerResource
    {
        return new CustomerResource($saveCustomer->handle($request->validated(), $customer));
    }

    public function destroy(Customer $customer): Response
    {
        Gate::authorize('delete', $customer);

        // Exclusão lógica (soft delete): preserva o histórico para futuras ordens de serviço
        DB::transaction(function () use ($customer) {
            $customer->vehicles()->delete();
            $customer->delete();
        });

        return response()->noContent();
    }

    /**
     * Busca por nome, nome fantasia, e-mail, CPF/CNPJ, telefones, placa, marca ou modelo.
     */
    private function applySearch(Builder $query, string $search): void
    {
        $term = '%'.mb_strtolower(trim($search)).'%';
        $digits = preg_replace('/\D/', '', $search);
        $alphanumeric = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($search));

        $query->where(function (Builder $query) use ($term, $digits, $alphanumeric) {
            $query->whereRaw('lower(name) like ?', [$term])
                ->orWhereRaw('lower(trade_name) like ?', [$term])
                ->orWhereRaw('lower(email) like ?', [$term]);

            if (strlen($alphanumeric) >= 3) {
                $query->orWhere('document', 'like', "%{$alphanumeric}%");
            }

            if (strlen($digits) >= 3) {
                $query->orWhere('phone', 'like', "%{$digits}%")
                    ->orWhere('secondary_phone', 'like', "%{$digits}%");
            }

            $query->orWhereHas('vehicles', function (Builder $vehicles) use ($term, $alphanumeric) {
                // Agrupado para não "vazar" o OR para fora do filtro do relacionamento
                $vehicles->where(function (Builder $vehicles) use ($term, $alphanumeric) {
                    $vehicles->whereRaw('lower(model) like ?', [$term])
                        ->orWhereRaw('lower(brand) like ?', [$term]);

                    if (strlen($alphanumeric) >= 3) {
                        $vehicles->orWhere('plate', 'like', "%{$alphanumeric}%");
                    }
                });
            });
        });
    }
}
