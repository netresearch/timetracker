<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove the unused Account entity.
 *
 * `accounts` (id + name) and the `entries.account_id` foreign key have existed
 * since the first Doctrine model (2011) but were never wired into any save/read
 * path — every account_id is NULL. This drops the dormant FK + column and the
 * table. No data loss (the column carried no values).
 */
final class Version20260624_RemoveAccountEntity extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the unused Account entity (drop entries.account_id FK + column and the accounts table)';
    }

    public function up(Schema $schema): void
    {
        // The FK name varies by how the schema was created (entries_ibfk_3 from the
        // seed SQL, or a Doctrine-generated name) — discover it via the schema object
        // (platform-agnostic, dry-run safe) rather than a raw information_schema query.
        $fkName = null;
        if ($schema->hasTable('entries')) {
            foreach ($schema->getTable('entries')->getForeignKeys() as $foreignKey) {
                if ('accounts' === $foreignKey->getForeignTableName() && in_array('account_id', $foreignKey->getLocalColumns(), true)) {
                    $fkName = $foreignKey->getName();
                    break;
                }
            }
        }
        if (null !== $fkName) {
            $this->addSql(sprintf('ALTER TABLE entries DROP FOREIGN KEY %s', $fkName));
        }

        $this->addSql('ALTER TABLE entries DROP COLUMN account_id');
        $this->addSql('DROP TABLE IF EXISTS accounts');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE accounts (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8 ENGINE=InnoDB');
        $this->addSql('ALTER TABLE entries ADD account_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE entries ADD CONSTRAINT FK_entries_account FOREIGN KEY (account_id) REFERENCES accounts (id)');
    }
}
