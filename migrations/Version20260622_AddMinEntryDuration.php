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
 * Add the per-user min_entry_duration setting (minutes).
 *
 * A new worklog entry's end pre-fills to start + this many minutes (default 5).
 * Fresh installs created from sql/full.sql already have the column, so this
 * migration is conditional for long-lived databases.
 */
final class Version20260622_AddMinEntryDuration extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.min_entry_duration (per-user minimum entry duration in minutes)';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('users') && !$schema->getTable('users')->hasColumn('min_entry_duration')) {
            $this->addSql('ALTER TABLE users ADD min_entry_duration INT NOT NULL DEFAULT 5');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('users') && $schema->getTable('users')->hasColumn('min_entry_duration')) {
            $this->addSql('ALTER TABLE users DROP min_entry_duration');
        }
    }
}
