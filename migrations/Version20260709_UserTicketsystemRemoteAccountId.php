<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260709_UserTicketsystemRemoteAccountId extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-023 §3: users_ticket_systems.remote_account_id maps TT users to Jira author identities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users_ticket_systems ADD remote_account_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_uts_remote_account ON users_ticket_systems (ticket_system_id, remote_account_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_uts_remote_account ON users_ticket_systems');
        $this->addSql('ALTER TABLE users_ticket_systems DROP COLUMN remote_account_id');
    }
}
