<?php

declare(strict_types=1);

namespace App\Transaction;

/**
 * Raw transaction form input (strings as typed). Validated by TransactionService::validate().
 * Shared by the transaction dialog and the dashboard quick-add form.
 */
final class TransactionInput
{
    public function __construct(
        public string $type = 'expense',
        public string $amount = '',
        public string $categoryId = '',
        public string $date = '',
        public string $note = '',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $s = static fn (string $k): string => \is_scalar($data[$k] ?? null) ? trim((string) $data[$k]) : '';

        return new self('income' === $s('type') ? 'income' : 'expense', $s('amount'), $s('category'), $s('date'), \is_scalar($data['note'] ?? null) ? (string) $data['note'] : '');
    }
}
