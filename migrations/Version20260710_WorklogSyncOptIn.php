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
        return 'ADR-023 amendment: per-user sync opt-in flags (sync_enabled, sync_all) on users_ticket_systems';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users_ticket_systems ADD sync_enabled TINYINT(1) NOT NULL DEFAULT 0, ADD sync_all TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users_ticket_systems DROP COLUMN sync_enabled, DROP COLUMN sync_all');
    }
}
