<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Budget\BudgetService;
use App\Category\CategoryService;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Finance\PeriodResolver;
use App\Repository\CategoryRepository;
use App\Tests\Support\Accounts;
use App\Tests\Support\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Categories screen over HTTP: create / rename / delete (with reassignment), the last-category
 * rule, validation and isolation between accounts.
 */
final class CategoryTest extends WebTestCase
{
    use Accounts;
    use ResetsDatabase;

    private KernelBrowser $client;
    private User $owner;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot(); // one container (and entity manager) for the whole test
        self::resetDatabase();
        $this->owner = self::createAccount('owner@example.com');
        $this->client->loginUser($this->owner);
    }

    public function testListShowsBothTypesWithCountsTransactionsAndBudgets(): void
    {
        self::addTransaction($this->owner, 'Dining out', 1500, 'today');
        self::addTransaction($this->owner, 'Dining out', 2500, 'today');
        $this->budgets()->setCategoryLimit($this->owner, $this->currentMonth(), self::category($this->owner, 'Dining out'), 15000);

        $crawler = $this->client->request('GET', '/categories');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#tab-expense .tabs__count', '12');
        self::assertSelectorTextContains('#tab-income .tabs__count', '6');
        $row = $crawler->filter('.cat-row')->reduce(static fn ($n) => str_contains($n->text(), 'Dining out'))->first();
        self::assertStringContainsString('2 transactions · '.(new \DateTimeImmutable())->format('F').' budget $150.00', $row->text());
        self::assertCount(6, $crawler->filter('#panel-income .cat-row'));
    }

    public function testCreateCategoryWithAutomaticLook(): void
    {
        $this->client->request('GET', '/categories/new?type=income');
        $this->client->submitForm('Create category', ['name' => '  Side   gigs ', 'type' => 'income']);

        self::assertResponseRedirects('/categories?type=income');
        $created = self::category($this->owner, 'Side gigs', TransactionType::Income);
        self::assertSame(7, $created->getPosition());
        self::assertSame('tag', $created->getIcon());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Category created');
    }

    public function testCreateValidation(): void
    {
        foreach ([
            ['', 'Enter a category name'],
            [str_repeat('x', 31), 'Use 30 characters or fewer'],
            ['GROCERIES', 'A category with this name already exists'],
        ] as [$name, $message]) {
            $this->client->request('GET', '/categories/new');
            $this->client->submitForm('Create category', ['name' => $name, 'type' => 'expense']);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('#cf-name-error', $message);
        }
        // Same name in the other type is allowed.
        $this->client->request('GET', '/categories/new');
        $this->client->submitForm('Create category', ['name' => 'Groceries', 'type' => 'income']);
        self::assertResponseRedirects();
    }

    public function testMutationsRequireCsrfToken(): void
    {
        $this->client->request('POST', '/categories/new', ['name' => 'Pets', 'type' => 'expense', '_token' => 'bad']);
        self::assertResponseStatusCodeSame(422);
        $id = self::category($this->owner, 'Other income', TransactionType::Income)->getId();
        $this->client->request('POST', "/categories/$id/delete", ['_token' => 'bad']);
        self::assertResponseStatusCodeSame(422);
        self::assertNotNull($this->repo()->find($id));
    }

    public function testRenameKeepsUniquenessCaseInsensitive(): void
    {
        $id = self::category($this->owner, 'Dining out')->getId();

        $this->client->request('GET', "/categories/$id/rename");
        $this->client->submitForm('Save name', ['name' => 'groceries']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#cf-name-error', 'A category with this name already exists');

        $this->client->submitForm('Save name', ['name' => 'Eating out']);
        self::assertResponseRedirects('/categories?type=expense');
        self::assertSame('Eating out', $this->repo()->find($id)?->getName());

        // Changing only the letter case of its own name is allowed.
        $this->client->request('GET', "/categories/$id/rename");
        $this->client->submitForm('Save name', ['name' => 'EATING OUT']);
        self::assertResponseRedirects();
        self::assertSame('EATING OUT', $this->repo()->find($id)?->getName());
    }

    public function testDeleteUnusedCategory(): void
    {
        $id = self::category($this->owner, 'Other income', TransactionType::Income)->getId();

        $crawler = $this->client->request('GET', "/categories/$id/delete");
        self::assertSelectorTextContains('#cde-title', 'Delete “Other income”?');
        self::assertSelectorTextContains('#cde-desc', 'It has no transactions.');
        $this->client->submit($crawler->filter('#cat-delete-empty form')->form());

        self::assertResponseRedirects('/categories?type=income');
        self::assertNull($this->repo()->find($id));
    }

    public function testDeleteWithTransactionsRequiresReassignmentAndRemovesBudgets(): void
    {
        $dining = self::category($this->owner, 'Dining out');
        $groceries = self::category($this->owner, 'Groceries');
        $tx1 = self::addTransaction($this->owner, 'Dining out', 1500, 'today');
        $tx2 = self::addTransaction($this->owner, 'Dining out', 2500, '-40 days');
        $month = $this->currentMonth();
        $prev = BudgetService::addMonths($month, -1);
        $this->budgets()->setCategoryLimit($this->owner, $prev, $dining, 10000);
        $this->budgets()->setCategoryLimit($this->owner, $month, $dining, 15000);
        $this->budgets()->setCategoryLimit($this->owner, $month, $groceries, 40000);
        $diningId = $dining->getId();

        $crawler = $this->client->request('GET', "/categories/$diningId/delete");
        self::assertSelectorTextContains('#cdr-desc', '2 transactions use this category. Choose an expense category');
        self::assertSelectorTextContains('#cdr-budget-text', 'The Dining out budget for '.(new \DateTimeImmutable())->format('F Y').' will also be removed.');
        self::assertSelectorTextContains('#cdr-confirm', 'Move 2 transactions and delete');
        $options = $crawler->filter('#cdr-target option')->each(static fn ($o) => $o->text());
        self::assertNotContains('Dining out (2 transactions)', $options);
        self::assertContains('Groceries (no transactions)', $options);
        self::assertCount(12, $options); // placeholder + 11 other expense categories, no income ones

        // Without a target nothing happens.
        $this->client->submit($crawler->filter('#cat-delete-reassign form')->form());
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#cdr-target-error', 'Choose a category to move the transactions to');

        // An income category is not a valid target.
        $salary = self::category($this->owner, 'Salary', TransactionType::Income)->getId();
        $form = $crawler->filter('#cat-delete-reassign form')->form();
        $form->get('move_to')->disableValidation()->setValue((string) $salary);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);

        $this->client->submit($crawler->filter('#cat-delete-reassign form')->form(), ['move_to' => $groceries->getId()]);
        self::assertResponseRedirects('/categories?type=expense');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Moved 2 transactions to Groceries and deleted Dining out');

        $em = $this->em();
        $em->clear();
        self::assertNull($this->repo()->find($diningId));
        self::assertSame($groceries->getId(), $em->find(Transaction::class, $tx1->getId())?->getCategory()->getId());
        self::assertSame($groceries->getId(), $em->find(Transaction::class, $tx2->getId())?->getCategory()->getId());
        $budgets = $this->budgets()->forMonth($this->owner, $month);
        self::assertNull($budgets->forCategoryId($diningId));
        self::assertNotNull($budgets->forCategoryId($groceries->getId()));
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM category_budget WHERE category_id = ?', [$diningId]));
    }

    public function testLastCategoryOfATypeCannotBeDeleted(): void
    {
        $service = self::getContainer()->get(CategoryService::class);
        $income = $this->repo()->findByOwnerAndType($this->owner, TransactionType::Income);
        foreach (\array_slice($income, 1) as $c) {
            $service->delete($c);
        }
        $last = $income[0];

        $crawler = $this->client->request('GET', '/categories?type=income');
        self::assertSelectorTextContains('#panel-income .cat-row__lock', 'Last income category — can’t be deleted');
        self::assertCount(1, $crawler->filter('#panel-income .list-row__actions button[disabled]'));

        $this->client->request('POST', '/categories/'.$last->getId().'/delete', ['_token' => 'irrelevant']);
        self::assertResponseRedirects('/categories?type=income');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Can’t delete Salary');
        self::assertNotNull($this->repo()->find($last->getId()));
    }

    public function testAnotherAccountsCategoriesAreNotFoundAndCannotBeTargets(): void
    {
        $other = self::createAccount('other@example.com', 'EUR');
        $foreign = self::category($other, 'Groceries');
        $foreignId = $foreign->getId();
        $mine = self::category($this->owner, 'Dining out');
        $tx = self::addTransaction($this->owner, 'Dining out', 1000, 'today');

        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, "/categories/$foreignId/rename", ['name' => 'Hacked']);
            self::assertResponseStatusCodeSame(404);
            $this->client->request($method, "/categories/$foreignId/delete");
            self::assertResponseStatusCodeSame(404);
        }

        // Reassigning own transactions into the other account's category is rejected.
        $crawler = $this->client->request('GET', '/categories/'.$mine->getId().'/delete');
        $form = $crawler->filter('#cat-delete-reassign form')->form();
        $form->get('move_to')->disableValidation()->setValue((string) $foreignId);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);

        $this->em()->clear();
        self::assertSame('Groceries', $this->repo()->find($foreignId)?->getName());
        self::assertSame($mine->getId(), $this->em()->find(Transaction::class, $tx->getId())?->getCategory()->getId());
        self::assertNotNull($this->repo()->find($mine->getId()));
    }

    public function testDialogsAreHtmxFragmentsAndSuccessTriggersToast(): void
    {
        $this->client->request('GET', '/categories/new?type=income', server: ['HTTP_HX_REQUEST' => 'true']);
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<html', $html);
        self::assertStringContainsString('data-open-on-load', $html);
        $token = $this->client->getCrawler()->filter('input[name=_token]')->attr('value');

        $this->client->request('POST', '/categories/new', ['name' => 'Gifts', 'type' => 'income', '_token' => $token], server: ['HTTP_HX_REQUEST' => 'true', 'HTTP_HX_CURRENT_URL' => 'http://localhost/categories?type=income']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A category with this name already exists', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/categories/new', ['name' => 'Royalties', 'type' => 'income', '_token' => $token], server: ['HTTP_HX_REQUEST' => 'true', 'HTTP_HX_CURRENT_URL' => 'http://localhost/categories']);
        self::assertResponseIsSuccessful();
        $trigger = json_decode((string) $this->client->getResponse()->headers->get('HX-Trigger'), true);
        self::assertSame('Category created', $trigger['em:toast']['title']);
        self::assertStringContainsString('/categories?type=income', (string) $this->client->getResponse()->headers->get('HX-Location'));
    }

    public function testAnonymousIsRedirectedToSignIn(): void
    {
        self::ensureKernelShutdown();
        $anon = self::createClient();
        $anon->request('GET', '/categories');
        self::assertResponseRedirects('/login');
    }

    private function repo(): CategoryRepository
    {
        return self::getContainer()->get(CategoryRepository::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function budgets(): BudgetService
    {
        return self::getContainer()->get(BudgetService::class);
    }

    private function currentMonth(): string
    {
        return self::getContainer()->get(PeriodResolver::class)->currentMonth();
    }
}
