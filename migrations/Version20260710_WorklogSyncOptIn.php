<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260710_WorklogSyncOptIn extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-023 amendment: per-user sync opt-in flags (sync_enabled, sync_all) on users_ticket_systems; drop superseded central sync-user + cursor columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users_ticket_systems ADD COLUMN IF NOT EXISTS sync_enabled TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN IF NOT EXISTS sync_all TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE ticket_systems DROP FOREIGN KEY fk_ts_sync_user');
        $this->addSql('ALTER TABLE ticket_systems DROP COLUMN IF EXISTS sync_user_id, DROP COLUMN IF EXISTS worklog_sync_cursor');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_systems ADD COLUMN IF NOT EXISTS sync_user_id INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS worklog_sync_cursor BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_systems ADD CONSTRAINT fk_ts_sync_user FOREIGN KEY IF NOT EXISTS (sync_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE users_ticket_systems DROP COLUMN IF EXISTS sync_enabled, DROP COLUMN IF EXISTS sync_all');
    }
}
