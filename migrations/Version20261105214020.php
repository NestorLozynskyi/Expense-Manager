<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Technical baseline: user accounts (Symfony Security) + temporary stack-demo table.
 */
final class Version20261105214020 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user table (unique email, password hash) and temporary tech_demo_ping table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tech_demo_ping (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              created_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE "user" (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              email VARCHAR(180) NOT NULL,
              roles CLOB NOT NULL,
              password VARCHAR(255) NOT NULL,
              created_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email ON "user" (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tech_demo_ping');
        $this->addSql('DROP TABLE "user"');
    }
}
