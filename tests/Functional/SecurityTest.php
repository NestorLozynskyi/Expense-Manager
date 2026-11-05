<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class SecurityTest extends WebTestCase
{
    use ResetsDatabase;

    public function testAnonymousCannotAccessProtectedRoute(): void
    {
        $client = self::createClient();
        self::resetDatabase();

        $client->request('GET', '/app/tech/secure-check');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertFalse(json_validate((string) $client->getResponse()->getContent()), 'Protected JSON payload must not be returned.');
    }

    public function testAuthenticatedUserCanAccessProtectedRoute(): void
    {
        $client = self::createClient();
        self::resetDatabase();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User('owner@example.com'))->setPassword('not-used-in-this-test');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/app/tech/secure-check');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['authenticated' => true, 'email' => 'owner@example.com'],
            json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testPublicPagesDoNotRequireAuthentication(): void
    {
        $client = self::createClient();
        self::resetDatabase();

        $client->request('GET', '/healthz');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/tech/demo');
        self::assertResponseIsSuccessful();
    }
}
