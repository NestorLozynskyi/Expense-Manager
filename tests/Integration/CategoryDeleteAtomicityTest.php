<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Budget\BudgetService;
use App\Category\CategoryRuleException;
use App\Category\CategoryService;
use App\Entity\Category;
use App\Entity\TransactionType;
use App\Tests\Support\Accounts;
use App\Tests\Support\ResetsDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** CategoryService::delete — rules and all-or-nothing reassignment. */
final class CategoryDeleteAtomicityTest extends KernelTestCase
{
    use Accounts;
    use ResetsDatabase;

    protected function setUp(): void
    {
        self::bootKernel();
        self::resetDatabase();
    }

    public function testFailureDuringDeleteLeavesTransactionsAndBudgetsUntouched(): void
    {
        $owner = self::createAccount();
        $dining = self::category($owner, 'Dining out');
        $groceries = self::category($owner, 'Groceries');
        self::addTransaction($owner, 'Dining out', 1500, 'today');
        self::addTransaction($owner, 'Dining out', 2500, 'today');
        self::getContainer()->get(BudgetService::class)->setCategoryLimit($owner, (new \DateTimeImmutable())->format('Y-m'), $dining, 15000);
        $diningId = $dining->getId();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getEventManager()->addEventListener(Events::onFlush, new class {
            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityDeletions() as $entity) {
                    if ($entity instanceof Category) {
                        throw new \RuntimeException('Simulated failure while deleting the category');
                    }
                }
            }
        });

        try {
            self::getContainer()->get(CategoryService::class)->delete($dining, $groceries);
            self::fail('Expected the simulated failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('Simulated failure while deleting the category', $e->getMessage());
        }

        $db = self::getContainer()->get(Connection::class);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM category WHERE id = ?', [$diningId]));
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM money_transaction WHERE category_id = ?', [$diningId]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM money_transaction WHERE category_id = ?', [$groceries->getId()]));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM category_budget WHERE category_id = ?', [$diningId]));
    }

    public function testRules(): void
    {
        $owner = self::createAccount();
        $service = self::getContainer()->get(CategoryService::class);
        $dining = self::category($owner, 'Dining out');
        self::addTransaction($owner, 'Dining out', 1500, 'today');

        // Transactions without a target.
        try {
            $service->delete($dining);
            self::fail('Expected a rule exception.');
        } catch (CategoryRuleException $e) {
            self::assertSame('Choose a category to move the transactions to', $e->getMessage());
        }

        // Target of the other type.
        $this->expectException(CategoryRuleException::class);
        $service->delete($dining, self::category($owner, 'Salary', TransactionType::Income));
    }

    public function testLastCategoryOfTypeIsProtected(): void
    {
        $owner = self::createAccount();
        $service = self::getContainer()->get(CategoryService::class);
        $income = self::getContainer()->get(\App\Repository\CategoryRepository::class)->findByOwnerAndType($owner, TransactionType::Income);
        foreach (\array_slice($income, 1) as $c) {
            $service->delete($c);
        }

        $this->expectException(CategoryRuleException::class);
        $this->expectExceptionMessage('This is your last income category');
        $service->delete($income[0]);
    }

    public function testNamesAreUniquePerAccountAndTypeIgnoringCase(): void
    {
        $owner = self::createAccount();
        $other = self::createAccount('other@example.com');
        $service = self::getContainer()->get(CategoryService::class);

        self::assertSame('A category with this name already exists', $service->validateName($owner, TransactionType::Expense, ' dining   OUT '));
        self::assertNull($service->validateName($owner, TransactionType::Income, 'Dining out'));
        self::assertNull($service->validateName($other, TransactionType::Expense, 'Pets'));
        $service->create($owner, TransactionType::Expense, 'Pets');
        self::assertNull($service->validateName($other, TransactionType::Expense, 'Pets'));
        self::assertSame('Enter a category name', $service->validateName($owner, TransactionType::Expense, '   '));
    }
}
