<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Income or expense category of one account. Names are unique per account and type
 * (case-insensitive, enforced by the unique index on name_key).
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Table(name: 'category')]
#[ORM\UniqueConstraint(name: 'uniq_category_owner_type_name', fields: ['owner', 'type', 'nameKey'])]
class Category
{
    public const NAME_MAX = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 10, enumType: TransactionType::class)]
    private TransactionType $type;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $nameKey;

    #[ORM\Column(length: 7)]
    private string $color;

    #[ORM\Column(length: 32)]
    private string $icon;

    #[ORM\Column]
    private int $position;

    public function __construct(User $owner, TransactionType $type, string $name, string $color, string $icon, int $position = 0)
    {
        $this->owner = $owner;
        $this->type = $type;
        $this->rename($name);
        $this->color = $color;
        $this->icon = $icon;
        $this->position = $position;
    }

    public static function nameKey(string $name): string
    {
        return mb_strtolower(self::cleanName($name));
    }

    public static function cleanName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    public function rename(string $name): void
    {
        $this->name = self::cleanName($name);
        $this->nameKey = self::nameKey($name);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getType(): TransactionType
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
