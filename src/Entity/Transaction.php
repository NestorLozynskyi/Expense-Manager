<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Income or expense. The type is the type of its category (a category never changes type).
 * Amount is stored as a positive integer in minor units of the account currency
 * (e.g. cents; JPY has none) — no floating point anywhere.
 */
#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'money_transaction')]
#[ORM\Index(name: 'idx_tx_owner_date', fields: ['owner', 'date'])]
class Transaction
{
    public const NOTE_MAX = 140;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Category $category;

    #[ORM\Column(type: Types::BIGINT)]
    private int|string $amountMinor;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: self::NOTE_MAX, nullable: true)]
    private ?string $note;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, Category $category, int $amountMinor, \DateTimeImmutable $date, ?string $note, ?\DateTimeImmutable $createdAt = null)
    {
        $this->owner = $owner;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->update($category, $amountMinor, $date, $note);
    }

    public function update(Category $category, int $amountMinor, \DateTimeImmutable $date, ?string $note): void
    {
        if ($category->getOwner() !== $this->owner) {
            throw new \InvalidArgumentException('Category belongs to another account.');
        }
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }
        $this->category = $category;
        $this->amountMinor = $amountMinor;
        $this->date = $date->setTime(0, 0);
        $note = null === $note ? null : trim($note);
        $this->note = '' === $note ? null : $note;
    }

    public function moveTo(Category $category): void
    {
        if ($category->getType() !== $this->category->getType() || $category->getOwner() !== $this->owner) {
            throw new \InvalidArgumentException('Transactions can only move to a category of the same type and account.');
        }
        $this->category = $category;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function getType(): TransactionType
    {
        return $this->category->getType();
    }

    public function getAmountMinor(): int
    {
        return (int) $this->amountMinor;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
