<?php

namespace App\Enums;

/** Tipos de entrada que não vêm de OS. */
enum IncomeCategory: string
{
    case OwnerContribution = 'owner_contribution';
    case Loan = 'loan';
    case AssetSale = 'asset_sale';
    case Refund = 'refund';
    case Investment = 'investment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::OwnerContribution => 'Aporte do dono',
            self::Loan => 'Empréstimo / financiamento',
            self::AssetSale => 'Venda de bens / sucata',
            self::Refund => 'Reembolso / devolução',
            self::Investment => 'Rendimentos',
            self::Other => 'Outras entradas',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $category) => [$category->value => $category->label()])->all();
    }
}
