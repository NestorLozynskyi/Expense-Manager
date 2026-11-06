<?php

declare(strict_types=1);

namespace App\Account;

use App\Category\DefaultCategories;
use App\Entity\User;
use App\Money\Currencies;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates a permanent account (hashed password, chosen currency) with the default categories.
 */
final readonly class AccountRegistrar
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private UserPasswordHasherInterface $hasher,
        private DefaultCategories $defaultCategories,
        private Currencies $currencies,
        private ClockInterface $clock,
    ) {
    }

    public function emailTaken(string $email): bool
    {
        return null !== $this->users->findOneByEmail($email);
    }

    public function register(RegistrationData $data): User
    {
        $currency = strtoupper((string) $data->currency);
        if (!$this->currencies->isValid($currency)) {
            throw new \InvalidArgumentException('Unsupported currency.');
        }
        $user = new User((string) $data->email, $currency, $this->clock->now());
        $user->setPassword($this->hasher->hashPassword($user, (string) $data->password));

        $this->em->wrapInTransaction(function () use ($user): void {
            $this->em->persist($user);
            $this->defaultCategories->createFor($user);
            $this->em->flush();
        });

        return $user;
    }
}
