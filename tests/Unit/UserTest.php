<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testEmailIsNormalisedAndUsedAsIdentifier(): void
    {
        $user = new User('  Jane.Doe@Example.COM ', 'USD');

        self::assertSame('jane.doe@example.com', $user->getEmail());
        self::assertSame('jane.doe@example.com', $user->getUserIdentifier());
    }

    public function testEveryUserHasRoleUserExactlyOnce(): void
    {
        $user = new User('a@example.com', 'USD');
        self::assertSame(['ROLE_USER'], $user->getRoles());

        $user->setRoles(['ROLE_USER', 'ROLE_X']);
        self::assertSame(['ROLE_USER', 'ROLE_X'], $user->getRoles());
    }

    public function testSerializedUserDoesNotContainPasswordHash(): void
    {
        $user = (new User('a@example.com', 'USD'))->setPassword('$2y$04$secret-hash-value');

        self::assertStringNotContainsString('secret-hash-value', serialize($user));
    }
}
