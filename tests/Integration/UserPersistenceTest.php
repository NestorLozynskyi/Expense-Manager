<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Support\ResetsDatabase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserPersistenceTest extends KernelTestCase
{
    use ResetsDatabase;

    protected function setUp(): void
    {
        self::bootKernel();
        self::resetDatabase();
    }

    public function testUsesSeparateSqliteTestDatabase(): void
    {
        $params = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->getParams();

        self::assertStringEndsWith('/var/data/test.db', $params['path'] ?? '');
    }

    public function testUserIsStoredWithHashedPasswordAndFoundByEmail(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User('Owner@Example.com');
        $user->setPassword($hasher->hashPassword($user, 'correct horse battery staple'));
        $em->persist($user);
        $em->flush();
        $em->clear();

        $found = self::getContainer()->get(UserRepository::class)->findOneByEmail('OWNER@example.com');

        self::assertNotNull($found);
        self::assertNotSame('correct horse battery staple', $found->getPassword());
        self::assertTrue($hasher->isPasswordValid($found, 'correct horse battery staple'));
        self::assertFalse($hasher->isPasswordValid($found, 'wrong password'));
    }

    public function testEmailIsUnique(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new User('dup@example.com'))->setPassword('x'));
        $em->flush();

        $this->expectException(UniqueConstraintViolationException::class);

        $em->persist((new User('DUP@example.com'))->setPassword('y'));
        $em->flush();
    }
}
