<?php

declare(strict_types=1);

namespace App\Transaction;

use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Finance\PeriodResolver;
use App\Money\MoneyFormatter;
use App\Money\MoneyParser;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Validates and stores transactions of one account (rule 4: type, amount > 0, category of the
 * same type and account, date, optional note ≤ 140). Messages are the design's copy.
 */
final readonly class TransactionService
{
    public function __construct(
        private CategoryRepository $categories,
        private EntityManagerInterface $em,
        private PeriodResolver $periods,
    ) {
    }

    /**
     * Errors are keyed by field: amount, category, date, note.
     *
     * @return array{errors: array<string, string>, amount: ?int, category: ?Category, date: ?\DateTimeImmutable}
     */
    public function validate(User $owner, TransactionInput $in): array
    {
        $errors = [];
        $amount = null;
        try {
            $amount = MoneyParser::parse($in->amount, $owner->getCurrency());
        } catch (\InvalidArgumentException $e) {
            $errors['amount'] = $e->getMessage();
        }

        $category = ctype_digit($in->categoryId) ? $this->categories->findOwned($owner, (int) $in->categoryId) : null;
        if (!$category || $category->getType() !== TransactionType::from($in->type)) {
            $category = null;
            $errors['category'] = 'Choose a category';
        }

        $date = PeriodResolver::parseDate($in->date);
        if (!$date) {
            $errors['date'] = 'Choose a date';
        }

        if (mb_strlen(trim($in->note)) > Transaction::NOTE_MAX) {
            $errors['note'] = 'Keep the note to '.Transaction::NOTE_MAX.' characters or fewer';
        }

        return ['errors' => $errors, 'amount' => $amount, 'category' => $category, 'date' => $date];
    }

    /** @return Transaction|array<string, string> the new transaction or validation errors */
    public function create(User $owner, TransactionInput $in): Transaction|array
    {
        $v = $this->validate($owner, $in);
        if ($v['errors']) {
            return $v['errors'];
        }
        $tx = new Transaction($owner, $v['category'], $v['amount'], $v['date'], $in->note);
        $this->em->persist($tx);
        $this->em->flush();

        return $tx;
    }

    /** @return Transaction|array<string, string> */
    public function update(Transaction $tx, TransactionInput $in): Transaction|array
    {
        $v = $this->validate($tx->getOwner(), $in);
        if ($v['errors']) {
            return $v['errors'];
        }
        $tx->update($v['category'], $v['amount'], $v['date'], $in->note);
        $this->em->flush();

        return $tx;
    }

    public function delete(Transaction $tx): void
    {
        $this->em->remove($tx);
        $this->em->flush();
    }

    /** Empty input for a new transaction dated today. */
    public function blank(string $type = 'expense'): TransactionInput
    {
        return new TransactionInput('income' === $type ? 'income' : 'expense', '', '', $this->periods->today()->format('Y-m-d'));
    }

    public static function inputFrom(Transaction $tx): TransactionInput
    {
        return new TransactionInput(
            $tx->getType()->value,
            MoneyFormatter::toInput($tx->getAmountMinor(), $tx->getOwner()->getCurrency()),
            (string) $tx->getCategory()->getId(),
            $tx->getDate()->format('Y-m-d'),
            (string) $tx->getNote(),
        );
    }
}
