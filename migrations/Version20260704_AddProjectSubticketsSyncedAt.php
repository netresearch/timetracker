<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add `projects.subtickets_synced_at` so the admin can show when each project's
 * Jira subtickets were last refreshed and the cron/manual sync can be judged for
 * staleness. NULL (default) = never synced; every existing row stays NULL on
 * upgrade and is unaffected until the next sync stamps it.
 */
final class Version20260704_AddProjectSubticketsSyncedAt extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable projects.subtickets_synced_at timestamp for subticket sync freshness';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects ADD subtickets_synced_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects DROP COLUMN subtickets_synced_at');
    }
}
