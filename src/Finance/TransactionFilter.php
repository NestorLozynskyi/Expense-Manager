<?php

declare(strict_types=1);

namespace App\Finance;

use App\Entity\Category;
use App\Entity\TransactionType;

final readonly class TransactionFilter
{
    public function __construct(
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public ?TransactionType $type = null,
        public ?Category $category = null,
    ) {
    }

    public static function forPeriod(Period $period, ?TransactionType $type = null, ?Category $category = null): self
    {
        return new self($period->from, $period->to, $type, $category);
    }
}
