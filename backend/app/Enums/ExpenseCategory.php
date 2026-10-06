<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Parts = 'parts';
    case Rent = 'rent';
    case Payroll = 'payroll';
    case Utilities = 'utilities';
    case Taxes = 'taxes';
    case Tools = 'tools';
    case Outsourced = 'outsourced';
    case Transport = 'transport';
    case Marketing = 'marketing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Parts => 'Peças e estoque',
            self::Rent => 'Aluguel',
            self::Payroll => 'Salários e encargos',
            self::Utilities => 'Água, luz e internet',
            self::Taxes => 'Impostos e taxas',
            self::Tools => 'Ferramentas e equipamentos',
            self::Outsourced => 'Serviços de terceiros',
            self::Transport => 'Combustível e transporte',
            self::Marketing => 'Marketing',
            self::Other => 'Outros',
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
