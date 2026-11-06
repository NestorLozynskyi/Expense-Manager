<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes the temporary stack-demo table of the technical skeleton (TASK-001).
 */
final class Version20261106000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop temporary tech_demo_ping table (technical skeleton demo)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE tech_demo_ping');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tech_demo_ping (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              created_at DATETIME NOT NULL
            )
        SQL);
    }
}
