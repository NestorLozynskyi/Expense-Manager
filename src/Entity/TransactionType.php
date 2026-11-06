<?php

declare(strict_types=1);

namespace App\Entity;

enum TransactionType: string
{
    case Expense = 'expense';
    case Income = 'income';

    public function label(): string
    {
        return match ($this) {
            self::Expense => 'Expense',
            self::Income => 'Income',
        };
    }

    /** Icon id from the design sprite: income = money in, expense = money out. */
    public function icon(): string
    {
        return match ($this) {
            self::Expense => 'arrow-up-right',
            self::Income => 'arrow-down-left',
        };
    }
}
