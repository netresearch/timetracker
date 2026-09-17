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
 * Cover the per-user last-booking aggregate that /getAllProjects runs for #687.
 *
 * `SELECT project_id, MAX(day) FROM entries WHERE project_id IS NOT NULL AND
 * user_id = ? GROUP BY project_id` (LastActivityTrait with a $userId) had no
 * index carrying all three columns: `idx_entries_user_project (user_id,
 * project_id)` serves the predicate but not `MAX(day)`, `idx_entries_user_day`
 * lacks `project_id`, and `idx_entries_project_day` cannot take the `user_id`
 * predicate. EXPLAIN on production confirmed `type: ref`, `key:
 * idx_entries_user_project`, `Extra: Using index condition; Using where` — one
 * row lookup per entry of that user, ~45k for the heaviest one, measured at
 * ~127 ms per call against a `SELECT 1` baseline.
 *
 * With (user_id, project_id, day) the same query becomes `type: range`, `Extra:
 * Using where; Using index` — index-only, no row lookups. Verified on
 * mariadb:10.11.16 (production's version) against 469,525 synthetic rows of the
 * same shape; note that probe's per-user slice is ~4.7k rows, a tenth of
 * production's heaviest user, so its wall-clock delta understates the gain.
 *
 * ASC on purpose, for the reason Version20260704_LastActivityIndexesAscLooseScan
 * documents: MariaDB 10.11 cannot loose-index-scan MIN/MAX over a DESC key part.
 */
final class Version20260917_EntriesUserProjectDayCoveringIndex extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace idx_entries_user_project with idx_entries_user_project_day (user_id, project_id, day) so the per-user last-booking aggregate is index-only';
    }

    public function up(Schema $schema): void
    {
        // Guarded because ADR-008 already publishes this index under this name, so an
        // installation may have applied it by hand; the entrypoint migrates under
        // `set -eu` and a duplicate-key error would keep the container from starting.
        $this->addSql('DROP INDEX IF EXISTS idx_entries_user_project_day ON entries');
        $this->addSql('CREATE INDEX idx_entries_user_project_day ON entries (user_id, project_id, day)');
        // (user_id, project_id) is a strict prefix of the new index and can serve
        // nothing it cannot, so keeping it would be write cost on every entry with no
        // read benefit. The user_id FK stays covered by the plain KEY (user_id).
        $this->addSql('DROP INDEX IF EXISTS idx_entries_user_project ON entries');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_entries_user_project ON entries (user_id, project_id)');
        $this->addSql('DROP INDEX IF EXISTS idx_entries_user_project_day ON entries');
    }
}
