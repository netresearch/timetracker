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
        // seed SQL, or a Doctrine-generated FK_… name) — discover it rather than assume.
        $fk = $this->connection->fetchOne(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'entries'
               AND COLUMN_NAME = 'account_id' AND REFERENCED_TABLE_NAME = 'accounts'
             LIMIT 1"
        );
        if (is_string($fk) && '' !== $fk) {
            $this->addSql(sprintf('ALTER TABLE entries DROP FOREIGN KEY `%s`', $fk));
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
