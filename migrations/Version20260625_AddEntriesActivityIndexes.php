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
 * Composite indexes for the admin "Last activity" column.
 *
 * The column runs `SELECT col, MAX(day) FROM entries GROUP BY col` for
 * customer_id / project_id. user_id already had a matching (user_id, day) index
 * (idx_entries_user_day); customer_id and project_id only had single-column FK
 * indexes, so the aggregate did a full ~466k-row scan (~900ms each on prod). The
 * matching (col, day) indexes let it scan the index instead (~900ms → ~120ms),
 * verified on production.
 */
final class Version20260625_AddEntriesActivityIndexes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add (customer_id, day) and (project_id, day) indexes for the admin last-activity aggregate';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_entries_customer_day ON entries (customer_id, day DESC)');
        $this->addSql('CREATE INDEX idx_entries_project_day ON entries (project_id, day DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_entries_customer_day ON entries');
        $this->addSql('DROP INDEX idx_entries_project_day ON entries');
    }
}
