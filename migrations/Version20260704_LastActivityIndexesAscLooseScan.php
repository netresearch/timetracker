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
 * Flip the last-activity composite indexes from (col, day DESC) to plain ASC so
 * MariaDB 10.11 can loose-index-scan the aggregate.
 *
 * The admin "last activity" aggregate `SELECT col, MAX(day) FROM entries GROUP BY col`
 * (col ∈ user_id / customer_id / project_id) full-INDEX-scanned all ~240k rows
 * (~150 ms) because these indexes were created `day DESC`
 * (Version20250901_AddPerformanceIndexes, Version20260625_AddEntriesActivityIndexes)
 * and **MariaDB 10.11 cannot apply a loose index scan ("Using index for group-by")
 * to MIN/MAX over a DESC key part** (fixed only in 11.4+/12.0 — MDEV-27576/32732).
 *
 * With a plain ASC (col, day) index the SAME 10.11 does the loose scan — reads a
 * few hundred index entries instead of ~233k, ~150 ms → ~1 ms — verified on
 * production data (only the index direction was changed). The ASC index still
 * serves the `ORDER BY day DESC` recent-first listings via a backward index scan
 * (no filesort — also verified), so those queries do not regress. The DESC was
 * a near-miss: it turned a full-table scan into a full-index scan, but ASC turns
 * it into a loose scan.
 */
final class Version20260704_LastActivityIndexesAscLooseScan extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Flip idx_entries_{user,customer,project}_day from (col, day DESC) to ASC so the last-activity MAX aggregate loose-scans on MariaDB 10.11';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_entries_user_day ON entries');
        $this->addSql('CREATE INDEX idx_entries_user_day ON entries (user_id, day)');
        $this->addSql('DROP INDEX idx_entries_customer_day ON entries');
        $this->addSql('CREATE INDEX idx_entries_customer_day ON entries (customer_id, day)');
        $this->addSql('DROP INDEX idx_entries_project_day ON entries');
        $this->addSql('CREATE INDEX idx_entries_project_day ON entries (project_id, day)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_entries_user_day ON entries');
        $this->addSql('CREATE INDEX idx_entries_user_day ON entries (user_id, day DESC)');
        $this->addSql('DROP INDEX idx_entries_customer_day ON entries');
        $this->addSql('CREATE INDEX idx_entries_customer_day ON entries (customer_id, day DESC)');
        $this->addSql('DROP INDEX idx_entries_project_day ON entries');
        $this->addSql('CREATE INDEX idx_entries_project_day ON entries (project_id, day DESC)');
    }
}
