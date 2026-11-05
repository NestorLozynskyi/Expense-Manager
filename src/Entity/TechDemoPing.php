<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TechDemoPingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * TEMPORARY technical entity used only by the stack demo page (/tech/demo).
 * Not part of the product domain; remove together with the demo page.
 */
#[ORM\Entity(repositoryClass: TechDemoPingRepository::class)]
#[ORM\Table(name: 'tech_demo_ping')]
class TechDemoPing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(?\DateTimeImmutable $createdAt = null)
    {
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
