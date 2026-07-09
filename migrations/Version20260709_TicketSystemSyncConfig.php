<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260709_TicketSystemSyncConfig extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-023 §5: ticket_systems sync user, default import activity, worklog cursor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_systems ADD sync_user_id INT DEFAULT NULL, ADD sync_default_activity_id INT DEFAULT NULL, ADD worklog_sync_cursor BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_systems ADD CONSTRAINT fk_ts_sync_user FOREIGN KEY (sync_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ticket_systems ADD CONSTRAINT fk_ts_sync_activity FOREIGN KEY (sync_default_activity_id) REFERENCES activities (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_systems DROP FOREIGN KEY fk_ts_sync_user');
        $this->addSql('ALTER TABLE ticket_systems DROP FOREIGN KEY fk_ts_sync_activity');
        $this->addSql('ALTER TABLE ticket_systems DROP COLUMN sync_user_id, DROP COLUMN sync_default_activity_id, DROP COLUMN worklog_sync_cursor');
    }
}
