<?php

declare(strict_types=1);

namespace App\Demo;

use App\Category\DefaultCategories;
use App\Entity\BudgetPlan;
use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Creates an isolated, temporary demo account (USD) with a fresh copy of the sample data.
 */
final readonly class DemoAccountFactory
{
    public const TTL = 'PT24H';
    public const WARNING_SECONDS = 600;
    public const EMAIL_DOMAIN = 'demo.expensemanager.app';

    public function __construct(
        private EntityManagerInterface $em,
        private DefaultCategories $defaultCategories,
        private ClockInterface $clock,
    ) {
    }

    public function create(): User
    {
        $now = $this->clock->now();
        $user = User::createDemo($this->uniqueEmail(), $now, new \DateInterval(self::TTL));
        $this->em->persist($user);

        $categories = $this->defaultCategories->createFor($user);
        $today = \DateTimeImmutable::createFromInterface($now)->setTime(0, 0);
        $firstOfMonth = $today->modify('first day of this month');

        foreach (DemoData::TRANSACTIONS as [$offset, $day, $key, $cents, $note]) {
            $monthStart = $firstOfMonth->modify(\sprintf('%+d month', $offset));
            $day = min($day, (int) $monthStart->format('t'));
            $date = $monthStart->setDate((int) $monthStart->format('Y'), (int) $monthStart->format('n'), $day);
            if ($date > $today) {
                $date = $today;
            }
            if ('Rent' === $note) {
                $note = 'Rent — '.$monthStart->format('F');
            }
            $this->em->persist(new Transaction($user, $categories[$key], $cents, $date, $note, $now));
        }

        foreach ([-1, 0] as $offset) {
            $plan = new BudgetPlan($user, $firstOfMonth->modify(\sprintf('%+d month', $offset))->format('Y-m'));
            $plan->setOverallLimit(DemoData::OVERALL_LIMIT);
            foreach (DemoData::CATEGORY_LIMITS as $key => $limit) {
                $plan->setCategoryLimit($categories[$key], $limit);
            }
            $this->em->persist($plan);
        }

        $this->em->flush();

        return $user;
    }

    private function uniqueEmail(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
        $local = '';
        for ($i = 0; $i < 10; ++$i) {
            $local .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
        }

        return 'demo-'.$local.'@'.self::EMAIL_DOMAIN;
    }
}
