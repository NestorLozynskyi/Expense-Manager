<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Account\AccountRegistrar;
use App\Account\RegistrationData;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test helpers: real accounts (registered through the app service, with default categories)
 * and transactions.
 *
 * @mixin KernelTestCase
 */
trait Accounts
{
    protected static function createAccount(string $email = 'owner@example.com', string $currency = 'USD', string $password = 'correct-horse-42'): User
    {
        $data = new RegistrationData();
        $data->email = $email;
        $data->password = $password;
        $data->currency = $currency;

        return static::getContainer()->get(AccountRegistrar::class)->register($data);
    }

    protected static function category(User $owner, string $name, TransactionType $type = TransactionType::Expense): Category
    {
        foreach (static::getContainer()->get(CategoryRepository::class)->findByOwnerAndType($owner, $type) as $c) {
            if ($c->getName() === $name) {
                return $c;
            }
        }
        throw new \LogicException("No category $name");
    }

    protected static function addTransaction(User $owner, string $category, int $amountMinor, string $date, ?string $note = null, TransactionType $type = TransactionType::Expense): Transaction
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tx = new Transaction($owner, static::category($owner, $category, $type), $amountMinor, new \DateTimeImmutable($date), $note);
        $em->persist($tx);
        $em->flush();

        return $tx;
    }
}
