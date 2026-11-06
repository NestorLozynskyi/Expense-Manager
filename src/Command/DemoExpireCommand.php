<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Support / end-to-end testing tool: move a demo account's expiry closer, e.g. to check the
 * 10-minute warning (`--in=540`) or the end of the session (`--in=0`). Only demo accounts.
 */
#[AsCommand(name: 'app:demo:expire', description: 'Set when a demo account expires (seconds from now)')]
final readonly class DemoExpireCommand
{
    public function __construct(private UserRepository $users, private EntityManagerInterface $em, private ClockInterface $clock)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Demo account email')] string $email, #[Option('Seconds from now')] int $in = 0): int
    {
        $user = $this->users->findOneByEmail($email);
        if (!$user || !$user->isDemo()) {
            $io->error('No demo account with this email.');

            return 1;
        }
        $user->expireDemoAt($this->clock->now()->modify(\sprintf('%+d seconds', $in)));
        $this->em->flush();
        $io->success('Demo account expires at '.$user->getDemoExpiresAt()?->format(\DATE_ATOM));

        return 0;
    }
}
