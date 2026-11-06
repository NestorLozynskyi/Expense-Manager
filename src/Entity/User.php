<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Account owner. Every user only ever sees their own data; there are no teams or roles.
 * One currency per account (ISO 4217), chosen at sign-up and never converted.
 * Demo accounts are temporary: they are deleted (with all data) after $demoExpiresAt.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', fields: ['email'])]
#[ORM\Index(name: 'idx_user_demo_expires', fields: ['demoExpiresAt'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    /** Password hash (never the plain password). */
    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $demoExpiresAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email, string $currency, ?\DateTimeImmutable $createdAt = null)
    {
        $this->email = self::normalizeEmail($email);
        $this->currency = strtoupper($currency);
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function createDemo(string $email, \DateTimeImmutable $now, \DateInterval $ttl): self
    {
        $user = new self($email, 'USD', $now);
        $user->demoExpiresAt = $now->add($ttl);

        return $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $passwordHash): static
    {
        $this->password = $passwordHash;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function isDemo(): bool
    {
        return null !== $this->demoExpiresAt;
    }

    public function getDemoExpiresAt(): ?\DateTimeImmutable
    {
        return $this->demoExpiresAt;
    }

    public function isDemoExpired(\DateTimeImmutable $now): bool
    {
        return null !== $this->demoExpiresAt && $now >= $this->demoExpiresAt;
    }

    /** Support/testing hook used by the `app:demo:expire` command. */
    public function expireDemoAt(\DateTimeImmutable $at): void
    {
        if (!$this->isDemo()) {
            throw new \LogicException('Only demo accounts expire.');
        }
        $this->demoExpiresAt = $at;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Initials for the avatar: "olena.kovalenko@…" → "OK", "demo-…" → "D". */
    public function getInitials(): string
    {
        if ($this->isDemo()) {
            return 'D';
        }
        $local = explode('@', $this->email)[0];
        $parts = array_values(array_filter(preg_split('/[._\-+]+/', $local) ?: []));
        $initials = mb_substr($parts[0] ?? $local, 0, 1).(isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '');

        return mb_strtoupper($initials ?: 'U');
    }

    /**
     * Do not keep the real password hash in the session.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }
}
