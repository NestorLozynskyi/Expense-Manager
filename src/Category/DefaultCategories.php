<?php

declare(strict_types=1);

namespace App\Category;

use App\Entity\Category;
use App\Entity\TransactionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The default category set every new account starts with (names, colors, icons from the design).
 */
final readonly class DefaultCategories
{
    /** key => [type, name, color, icon] — keys are also used by the demo data set. */
    public const SET = [
        'groceries' => ['expense', 'Groceries', '#0E7C66', 'cart'],
        'dining' => ['expense', 'Dining out', '#C2410C', 'utensils'],
        'housing' => ['expense', 'Housing', '#3A5BA9', 'home'],
        'utilities' => ['expense', 'Utilities', '#0369A1', 'bolt'],
        'transport' => ['expense', 'Transport', '#8A6A0B', 'bus'],
        'health' => ['expense', 'Health', '#BE185D', 'heart'],
        'shopping' => ['expense', 'Shopping', '#6B4FA0', 'bag'],
        'entertainment' => ['expense', 'Entertainment', '#4D7C0F', 'film'],
        'subscriptions' => ['expense', 'Subscriptions', '#475467', 'repeat'],
        'education' => ['expense', 'Education', '#155E75', 'book'],
        'travel' => ['expense', 'Travel', '#9F3A8A', 'plane'],
        'other' => ['expense', 'Other', '#667085', 'dots-circle'],
        'salary' => ['income', 'Salary', '#067647', 'briefcase'],
        'freelance' => ['income', 'Freelance', '#1F4FD8', 'laptop'],
        'gifts' => ['income', 'Gifts', '#B54708', 'gift'],
        'interest' => ['income', 'Interest', '#0E7490', 'percent'],
        'refunds' => ['income', 'Refunds', '#6B4FA0', 'rotate-ccw'],
        'other-income' => ['income', 'Other income', '#667085', 'plus-circle'],
    ];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return array<string, Category> keyed by SET key (persisted, not flushed) */
    public function createFor(User $user): array
    {
        $out = [];
        $position = ['expense' => 0, 'income' => 0];
        foreach (self::SET as $key => [$type, $name, $color, $icon]) {
            $category = new Category($user, TransactionType::from($type), $name, $color, $icon, ++$position[$type]);
            $this->em->persist($category);
            $out[$key] = $category;
        }

        return $out;
    }
}
