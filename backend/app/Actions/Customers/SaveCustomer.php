<?php

namespace App\Actions\Customers;

use App\Enums\PersonType;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Grava o cliente e sincroniza os veículos numa única transação:
 * itens com id são atualizados, sem id são criados e os ausentes são removidos.
 */
class SaveCustomer
{
    /**
     * @param  array<string, mixed>  $data  dados validados (SaveCustomerRequest)
     */
    public function handle(array $data, ?Customer $customer = null, ?User $actor = null): Customer
    {
        return DB::transaction(function () use ($data, $customer, $actor) {
            $vehicles = $data['vehicles'] ?? [];
            unset($data['vehicles']);

            // Campos que não se aplicam ao tipo de pessoa não são guardados
            if (($data['person_type'] ?? null) === PersonType::Company->value) {
                $data['birth_date'] = null;
            } else {
                $data['trade_name'] = null;
                $data['state_registration'] = null;
            }

            if ($customer === null) {
                $customer = new Customer;
                $customer->created_by = $actor?->id;
            }

            $customer->fill($data)->save();

            $keptIds = [];
            foreach ($vehicles as $vehicleData) {
                $id = $vehicleData['id'] ?? null;
                unset($vehicleData['id']);

                $vehicle = $id
                    ? $customer->vehicles()->findOrFail($id)
                    : $customer->vehicles()->make();

                $vehicle->fill($vehicleData)->save();
                $keptIds[] = $vehicle->id;
            }

            $customer->vehicles()->whereNotIn('id', $keptIds)->delete();

            return $customer->load('vehicles');
        });
    }
}
