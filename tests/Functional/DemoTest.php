<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Budget\BudgetService;
use App\Demo\DemoAccountFactory;
use App\Demo\DemoPurger;
use App\Demo\PurgeExpiredDemos;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Schedule;
use App\Tests\Support\Accounts;
use App\Tests\Support\ResetsDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Demo lifecycle with a controlled clock: start → isolated USD account with sample data →
 * 24 h → warning in the last 10 minutes → expiry ends the session and deletes everything.
 */
final class DemoTest extends WebTestCase
{
    use Accounts;
    use ClockSensitiveTrait;
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();
    }

    private function startDemo(): User
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form.auth-demo')->form());
        self::assertResponseRedirects('/dashboard');
        $email = $this->client->getContainer()->get('security.token_storage')->getToken()?->getUserIdentifier();
        self::assertNotNull($email);

        return self::getContainer()->get(UserRepository::class)->findOneByEmail($email);
    }

    public function testStartCreatesSeparateDemoAccountWithSampleData(): void
    {
        self::mockTime('2026-11-05 09:00:00');
        $demo = $this->startDemo();

        self::assertTrue($demo->isDemo());
        self::assertSame('USD', $demo->getCurrency());
        self::assertEquals(new \DateTimeImmutable('2026-11-06 09:00:00'), $demo->getDemoExpiresAt());
        self::assertCount(18, self::getContainer()->get(CategoryRepository::class)->findByOwner($demo));
        $tx = self::getContainer()->get(TransactionRepository::class);
        self::assertSame(75, $tx->count(['owner' => $demo]));

        // Several months of data relative to today; current month has budgets in all 3 states.
        $months = $tx->monthlyTotals($demo, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-11-30'));
        self::assertSame(['2026-08', '2026-09', '2026-10', '2026-11'], array_keys($months));
        $budgets = self::getContainer()->get(BudgetService::class)->forMonth($demo, '2026-11');
        self::assertGreaterThanOrEqual(1, $budgets->counts['over']);
        self::assertGreaterThanOrEqual(1, $budgets->counts['warning']);
        self::assertGreaterThanOrEqual(1, $budgets->counts['ok']);

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#em-demo-banner', 'deleted in 24 h');

        // A second demo (another browser) is a different, independent account.
        $this->client->restart();
        $other = $this->startDemo();
        self::assertNotSame($demo->getId(), $other->getId());
        self::assertSame(75, $tx->count(['owner' => $other]));
    }

    public function testDemoDataAlwaysHasOverAndNearLimitBudgetsInCurrentMonth(): void
    {
        $factory = self::getContainer()->get(DemoAccountFactory::class);
        foreach (['2026-11-01 00:05', '2026-12-31 23:00', '2027-03-01 12:00', '2028-02-29 08:00'] as $now) {
            self::mockTime($now);
            $demo = $factory->create();
            $b = self::getContainer()->get(BudgetService::class)->forMonth($demo, substr((new \DateTimeImmutable($now))->format('Y-m'), 0, 7));
            self::assertGreaterThanOrEqual(1, $b->counts['over'], $now);
            self::assertGreaterThanOrEqual(1, $b->counts['warning'], $now);
            $latest = self::getContainer()->get(TransactionRepository::class)->findOneBy(['owner' => $demo], ['date' => 'DESC']);
            self::assertLessThanOrEqual((new \DateTimeImmutable($now))->format('Y-m-d'), $latest->getDate()->format('Y-m-d'), 'no future-dated demo data');
        }
    }

    public function testReloadKeepsDemoAndWarningAppearsInLastTenMinutes(): void
    {
        $clock = self::mockTime('2026-11-05 09:00:00');
        $this->startDemo();
        $this->client->request('GET', '/dashboard');
        self::assertSelectorNotExists('#em-demo-banner.demo-banner--warning');

        $clock->modify('+23 hours 51 minutes');
        $this->client->request('GET', '/transactions');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#em-demo-banner.demo-banner--warning');
        self::assertSelectorTextContains('#em-demo-banner', 'deleted in 9 min');
        self::assertSelectorTextContains('#demo-expiring', 'Your demo ends in 10 minutes');
    }

    public function testExpiredSessionEndsAndAccountIsDeleted(): void
    {
        $clock = self::mockTime('2026-11-05 09:00:00');
        $demo = $this->startDemo();
        $id = $demo->getId();

        $clock->modify('+24 hours');
        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/demo/ended');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Your demo session has ended');
        self::assertSelectorExists('a[href="/register"]');
        self::assertSelectorExists('form[action="/demo"] button');

        $this->assertAccountGone($id);
        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testSessionShowsEndedScreenWhenSchedulerDeletedTheAccountFirst(): void
    {
        $clock = self::mockTime('2026-11-05 09:00:00');
        $demo = $this->startDemo();
        $clock->modify('+24 hours 30 seconds');
        self::assertSame(1, self::getContainer()->get(DemoPurger::class)->purgeExpired());

        $this->client->request('GET', '/transactions', server: ['HTTP_HX_REQUEST' => 'true']);
        self::assertSame('/demo/ended', $this->client->getResponse()->headers->get('HX-Redirect'), 'HTMX requests get a full-page redirect');
        $this->assertAccountGone($demo->getId());
    }

    public function testSchedulerPurgesExpiredDemosWithoutAnyRequest(): void
    {
        $clock = self::mockTime('2026-11-05 09:00:00');
        $factory = self::getContainer()->get(DemoAccountFactory::class);
        $old = $factory->create()->getId();
        $clock->modify('+2 hours');
        $fresh = $factory->create()->getId();
        $permanent = self::createAccount('keeper@example.com')->getId();
        self::addTransaction(self::getContainer()->get(UserRepository::class)->find($permanent), 'Groceries', 1000, '2026-11-01');

        // The recurring schedule contains the purge message …
        $messages = [];
        foreach (self::getContainer()->get(Schedule::class)->getSchedule()->getRecurringMessages() as $recurring) {
            foreach ($recurring->getMessages(new \Symfony\Component\Scheduler\Generator\MessageContext('default', $recurring->getId(), $recurring->getTrigger(), new \DateTimeImmutable())) as $m) {
                $messages[] = $m;
            }
        }
        self::assertContainsOnlyInstancesOf(PurgeExpiredDemos::class, $messages);

        // … and handling it after 24 h deletes only the expired demo.
        $clock->modify('+22 hours 1 minute');
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new PurgeExpiredDemos());
        $this->assertAccountGone($old);
        self::assertNotNull(self::getContainer()->get(UserRepository::class)->find($fresh));
        self::assertNotNull(self::getContainer()->get(UserRepository::class)->find($permanent));

        $clock->modify('+2 hours');
        self::assertSame(1, self::getContainer()->get(DemoPurger::class)->purgeExpired());
        $this->assertAccountGone($fresh);
        self::assertSame(1, self::getContainer()->get(TransactionRepository::class)->count([]));
    }

    public function testDemoStartIsRateLimitedAndNeedsCsrf(): void
    {
        $this->client->request('POST', '/demo', ['_token' => 'bogus']);
        self::assertResponseRedirects('/login');
        self::assertSame(0, self::getContainer()->get(UserRepository::class)->count([]));

        for ($i = 0; $i < 3; ++$i) {   // test env: 3 per hour per IP
            $this->client->restart();
            $this->startDemo();
        }
        $this->client->restart();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form.auth-demo')->form());
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast--error', 'Too many demo sessions right now');
        self::assertSame(3, self::getContainer()->get(UserRepository::class)->count([]));
    }

    public function testDemoUserCanSignUpForPermanentAccount(): void
    {
        $this->startDemo();
        $crawler = $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('#form-sign-up')->form([
            'signup[email]' => 'keeper@example.com', 'signup[password]' => 'maple-river-42', 'signup[currency]' => 'EUR',
        ]));
        self::assertResponseRedirects('/dashboard');
        $this->client->followRedirect();
        self::assertSelectorNotExists('#em-demo-banner');
        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('keeper@example.com');
        self::assertFalse($user->isDemo());
        self::assertSame(0, self::getContainer()->get(TransactionRepository::class)->count(['owner' => $user]), 'demo data is not transferred');
    }

    private function assertAccountGone(int $id): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $c = self::getContainer()->get(Connection::class);
        foreach (['"user" WHERE id', 'category WHERE owner_id', 'money_transaction WHERE owner_id', 'budget_plan WHERE owner_id'] as $where) {
            self::assertSame(0, (int) $c->fetchOne("SELECT COUNT(*) FROM $where = ?", [$id]), $where);
        }
    }
}
