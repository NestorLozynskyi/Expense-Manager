<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\DemoPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:demo:purge', description: 'Delete expired demo accounts and all their data now')]
final readonly class DemoPurgeCommand
{
    public function __construct(private DemoPurger $purger)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->success(\sprintf('Deleted %d expired demo account(s).', $this->purger->purgeExpired()));

        return 0;
    }
}
