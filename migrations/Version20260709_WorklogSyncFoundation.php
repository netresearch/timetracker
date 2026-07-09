<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260709_WorklogSyncFoundation extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-023: sync_runs + sync_run_items (run reports) and worklog_sync_state (lease base) tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE sync_runs (
                id INT AUTO_INCREMENT NOT NULL,
                ticket_system_id INT NOT NULL,
                triggered_by_id INT NOT NULL,
                type VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                scope JSON NOT NULL,
                counters JSON NOT NULL,
                continuation JSON DEFAULT NULL,
                started_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                finished_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_sync_runs_ticket_system (ticket_system_id),
                INDEX idx_sync_runs_triggered_by (triggered_by_id),
                PRIMARY KEY (id),
                CONSTRAINT fk_sync_runs_ticket_system FOREIGN KEY (ticket_system_id) REFERENCES ticket_systems (id) ON DELETE CASCADE,
                CONSTRAINT fk_sync_runs_triggered_by FOREIGN KEY (triggered_by_id) REFERENCES users (id) ON DELETE CASCADE
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE sync_run_items (
                id INT AUTO_INCREMENT NOT NULL,
                sync_run_id INT NOT NULL,
                entry_id INT DEFAULT NULL,
                kind VARCHAR(32) NOT NULL,
                issue_key VARCHAR(50) DEFAULT NULL,
                remote_worklog_id BIGINT DEFAULT NULL,
                author VARCHAR(255) DEFAULT NULL,
                reason VARCHAR(255) NOT NULL,
                payload JSON DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_sync_run_items_run_kind (sync_run_id, kind),
                INDEX idx_sync_run_items_entry (entry_id),
                PRIMARY KEY (id),
                CONSTRAINT fk_sync_run_items_run FOREIGN KEY (sync_run_id) REFERENCES sync_runs (id) ON DELETE CASCADE,
                CONSTRAINT fk_sync_run_items_entry FOREIGN KEY (entry_id) REFERENCES entries (id) ON DELETE SET NULL
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE worklog_sync_state (
                id INT AUTO_INCREMENT NOT NULL,
                entry_id INT NOT NULL,
                ticket_system_id INT NOT NULL,
                last_sync_run_id INT DEFAULT NULL,
                status VARCHAR(16) NOT NULL,
                base_payload JSON NOT NULL,
                base_updated_at VARCHAR(40) NOT NULL,
                conflict_remote_payload JSON DEFAULT NULL,
                last_synced_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_worklog_sync_state_entry (entry_id),
                INDEX idx_worklog_sync_state_status (status),
                PRIMARY KEY (id),
                CONSTRAINT fk_wss_entry FOREIGN KEY (entry_id) REFERENCES entries (id) ON DELETE CASCADE,
                CONSTRAINT fk_wss_ticket_system FOREIGN KEY (ticket_system_id) REFERENCES ticket_systems (id) ON DELETE CASCADE,
                CONSTRAINT fk_wss_last_run FOREIGN KEY (last_sync_run_id) REFERENCES sync_runs (id) ON DELETE SET NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE worklog_sync_state');
        $this->addSql('DROP TABLE sync_run_items');
        $this->addSql('DROP TABLE sync_runs');
    }
}
