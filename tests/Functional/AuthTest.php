<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\TransactionType;
use App\Repository\CategoryRepository;
use App\Repository\UserRepository;
use App\Tests\Support\Accounts;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sign up (email + password ≥ 8 + currency), default categories, sign in/out, access control.
 */
final class AuthTest extends WebTestCase
{
    use Accounts;
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();
    }

    public function testAnonymousIsRedirectedToSignIn(): void
    {
        foreach (['/dashboard', '/transactions', '/categories', '/budgets', '/transactions/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/login', 302, $url);
        }
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testSignUpCreatesAccountWithCurrencyAndDefaultCategories(): void
    {
        $crawler = $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-sign-up h1', 'Create your account');

        $form = $crawler->filter('#form-sign-up')->form([
            'signup[email]' => 'Olena.Kovalenko@Example.com',
            'signup[password]' => 'maple-river-42',
            'signup[currency]' => 'UAH',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/dashboard');

        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('olena.kovalenko@example.com');
        self::assertNotNull($user);
        self::assertSame('UAH', $user->getCurrency());
        self::assertFalse($user->isDemo());
        self::assertNotSame('maple-river-42', $user->getPassword());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'maple-river-42'));

        $categories = self::getContainer()->get(CategoryRepository::class);
        $names = static fn (TransactionType $t) => array_map(static fn ($c) => $c->getName(), $categories->findByOwnerAndType($user, $t));
        self::assertSame(['Groceries', 'Dining out', 'Housing', 'Utilities', 'Transport', 'Health', 'Shopping', 'Entertainment', 'Subscriptions', 'Education', 'Travel', 'Other'], $names(TransactionType::Expense));
        self::assertSame(['Salary', 'Freelance', 'Gifts', 'Interest', 'Refunds', 'Other income'], $names(TransactionType::Income));

        // Signed in right away.
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-card__meta', 'Currency: UAH');
    }

    public function testSignUpValidation(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->filter('#form-sign-up')->form([
            'signup[email]' => 'olena@example',
            'signup[password]' => 'kyiv26',
            'signup[currency]' => '',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#su-summary', 'Fix 3 fields to continue');
        self::assertSelectorTextContains('#su-email-error', 'Enter a valid email address, like name@example.com');
        self::assertSelectorTextContains('#su-password-error', 'Password must be at least 8 characters');
        self::assertSelectorTextContains('#su-currency-error', 'Choose your account currency');

        // Not an ISO 4217 currency in use.
        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->filter('#form-sign-up')->form([
            'signup[email]' => 'olena@example.com', 'signup[password]' => 'maple-river-42', 'signup[currency]' => 'XYZ',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#su-currency-error', 'Choose your account currency');
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('olena@example.com'));
    }

    public function testSignUpRejectsTakenEmailAndMissingCsrf(): void
    {
        self::createAccount('taken@example.com');
        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->filter('#form-sign-up')->form([
            'signup[email]' => 'TAKEN@example.com', 'signup[password]' => 'maple-river-42', 'signup[currency]' => 'USD',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#su-email-error', 'An account with this email already exists');

        $this->client->request('POST', '/register', ['signup' => ['email' => 'x@example.com', 'password' => 'maple-river-42', 'currency' => 'USD']]);
        self::assertResponseStatusCodeSame(422);
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('x@example.com'));
    }

    public function testSignInWrongPasswordThenSuccessThenSignOut(): void
    {
        self::createAccount('owner@example.com', 'EUR', 'correct-horse-42');

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('#form-sign-in')->form(['email' => 'owner@example.com', 'password' => 'wrong-password']));
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('#si-invalid', 'Email or password is incorrect');
        self::assertInputValueSame('email', 'owner@example.com');

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('#form-sign-in')->form(['email' => 'OWNER@example.com', 'password' => 'correct-horse-42']));
        self::assertResponseRedirects('/dashboard');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.account-card__email', 'owner@example.com');

        // Signed-in users skip the auth pages.
        $this->client->request('GET', '/login');
        self::assertResponseRedirects('/dashboard');

        $this->client->submit($crawler->filter('.sidebar__footer form')->form());
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testSignInRequiresCsrfToken(): void
    {
        self::createAccount('owner@example.com', 'USD', 'correct-horse-42');
        $this->client->request('POST', '/login', ['email' => 'owner@example.com', 'password' => 'correct-horse-42']);
        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }
}
