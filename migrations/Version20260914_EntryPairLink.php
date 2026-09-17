<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914_EntryPairLink extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-025: link the agent walltime entry and its delegated human estimate as a pair';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entries ADD COLUMN IF NOT EXISTS paired_entry_id INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_entries_paired_entry ON entries (paired_entry_id)');
        $this->addSql('ALTER TABLE entries
            ADD CONSTRAINT FK_entries_paired_entry FOREIGN KEY IF NOT EXISTS (paired_entry_id) REFERENCES entries (id) ON DELETE SET NULL');

        // Backfill pairs written by LogTimeTool::dualWrite before the link existed. It
        // writes the agent entry first and the delegated human estimate directly after
        // (adjacent ids), with the same user, day, start, project, activity, ticket and
        // description. Requiring all of that keeps the match unambiguous. Two cases stay
        // unlinked: a pair whose human half was edited since, and a pair whose inserts
        // interleaved with another concurrent log_time call, so its ids are not adjacent.
        // Deleting one half of such a pair leaves the other in place, as before.
        $this->addSql(<<<'SQL'
            UPDATE entries a
            JOIN entries h
              ON h.id = a.id + 1
             AND h.user_id = a.user_id
             AND h.day = a.day
             AND h.start = a.start
             AND h.project_id <=> a.project_id
             AND h.activity_id <=> a.activity_id
             AND h.ticket = a.ticket
             AND h.description = a.description
            SET a.paired_entry_id = h.id,
                h.paired_entry_id = a.id
            WHERE a.source = 'agent'
              AND h.source = 'human'
              AND h.estimated = 1
              AND a.paired_entry_id IS NULL
              AND h.paired_entry_id IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Guard every drop so a partially-applied down can be re-run.
        $this->addSql('ALTER TABLE entries DROP FOREIGN KEY IF EXISTS FK_entries_paired_entry');
        $this->addSql('ALTER TABLE entries DROP INDEX IF EXISTS UNIQ_entries_paired_entry');
        $this->addSql('ALTER TABLE entries DROP COLUMN IF EXISTS paired_entry_id');
    }
}
