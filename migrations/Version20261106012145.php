<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106012145 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'v1 schema: account currency + demo expiry, categories, transactions (minor units), monthly budget plans';
    }

    public function up(Schema $schema): void
    {
        // Existing (pre-v1) accounts get USD; the user table is rebuilt first so new FKs point at it.
        $this->addSql('CREATE TEMPORARY TABLE __temp__user AS SELECT id, email, roles, password, created_at FROM user');
        $this->addSql('DROP TABLE user');
        $this->addSql(<<<'SQL'
            CREATE TABLE user (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              email VARCHAR(180) NOT NULL,
              roles CLOB NOT NULL,
              password VARCHAR(255) NOT NULL,
              created_at DATETIME NOT NULL,
              currency VARCHAR(3) NOT NULL,
              demo_expires_at DATETIME DEFAULT NULL
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO user (
              id, email, roles, password, created_at, currency
            )
            SELECT
              id,
              email,
              roles,
              password,
              created_at,
              'USD'
            FROM
              __temp__user
        SQL);
        $this->addSql('DROP TABLE __temp__user');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email ON user (email)');
        $this->addSql('CREATE INDEX idx_user_demo_expires ON user (demo_expires_at)');
        $this->addSql(<<<'SQL'
            CREATE TABLE budget_plan (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              month VARCHAR(7) NOT NULL,
              overall_limit_minor BIGINT DEFAULT NULL,
              owner_id INTEGER NOT NULL,
              CONSTRAINT FK_A79E592D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_budget_plan_owner_month ON budget_plan (owner_id, month)');
        $this->addSql('CREATE INDEX IDX_A79E592D7E3C61F9 ON budget_plan (owner_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE category (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              type VARCHAR(10) NOT NULL,
              name VARCHAR(30) NOT NULL,
              name_key VARCHAR(30) NOT NULL,
              color VARCHAR(7) NOT NULL,
              icon VARCHAR(32) NOT NULL,
              position INTEGER NOT NULL,
              owner_id INTEGER NOT NULL,
              CONSTRAINT FK_64C19C17E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_category_owner_type_name ON category (owner_id, type, name_key)');
        $this->addSql('CREATE INDEX IDX_64C19C17E3C61F9 ON category (owner_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE category_budget (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              limit_minor BIGINT NOT NULL,
              plan_id INTEGER NOT NULL,
              category_id INTEGER NOT NULL,
              CONSTRAINT FK_7C1A3D00E899029B FOREIGN KEY (plan_id) REFERENCES budget_plan (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_7C1A3D0012469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_category_budget_plan_category ON category_budget (plan_id, category_id)
        SQL);
        $this->addSql('CREATE INDEX IDX_7C1A3D00E899029B ON category_budget (plan_id)');
        $this->addSql('CREATE INDEX IDX_7C1A3D0012469DE2 ON category_budget (category_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE money_transaction (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              amount_minor BIGINT NOT NULL,
              date DATE NOT NULL,
              note VARCHAR(140) DEFAULT NULL,
              created_at DATETIME NOT NULL,
              owner_id INTEGER NOT NULL,
              category_id INTEGER NOT NULL,
              CONSTRAINT FK_D21254E27E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_D21254E212469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_tx_owner_date ON money_transaction (owner_id, date)');
        $this->addSql('CREATE INDEX IDX_D21254E27E3C61F9 ON money_transaction (owner_id)');
        $this->addSql('CREATE INDEX IDX_D21254E212469DE2 ON money_transaction (category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE money_transaction');
        $this->addSql('DROP TABLE category_budget');
        $this->addSql('DROP TABLE budget_plan');
        $this->addSql('DROP TABLE category');
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__user AS
            SELECT
              id,
              email,
              roles,
              password,
              created_at
            FROM
              "user"
        SQL);
        $this->addSql('DROP TABLE "user"');
        $this->addSql(<<<'SQL'
            CREATE TABLE "user" (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              email VARCHAR(180) NOT NULL,
              roles CLOB NOT NULL,
              password VARCHAR(255) NOT NULL,
              created_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO "user" (
              id, email, roles, password, created_at
            )
            SELECT
              id,
              email,
              roles,
              password,
              created_at
            FROM
              __temp__user
        SQL);
        $this->addSql('DROP TABLE __temp__user');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email ON "user" (email)');
    }
}
