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
 * Add Jira Cloud (OAuth 2.0 / 3LO) support to the data model.
 *
 * Adds the per-ticket-system deployment discriminator and OAuth2 client
 * credentials, the resolved cloud id, and the per-user OAuth2 token columns.
 * tokensecret is relaxed to nullable so Cloud rows (which have no token secret)
 * can be stored. No behaviour change yet — the factory still returns the
 * OAuth-1 service for everyone.
 */
final class Version20260622_AddJiraCloudSupport extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Jira Cloud columns (deployment_type/oauth2 client credentials/cloud_id, refresh_token/token_expires_at, relax tokensecret)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE ticket_systems
            ADD deployment_type VARCHAR(15) NOT NULL DEFAULT 'SERVER',
            ADD oauth2_client_id VARCHAR(255) NULL,
            ADD oauth2_client_secret VARCHAR(255) NULL,
            ADD cloud_id VARCHAR(64) NULL");

        $this->addSql('ALTER TABLE users_ticket_systems
            ADD refresh_token TEXT NULL,
            ADD token_expires_at DATETIME NULL,
            MODIFY tokensecret TEXT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_systems
            DROP deployment_type,
            DROP oauth2_client_id,
            DROP oauth2_client_secret,
            DROP cloud_id');

        $this->addSql('ALTER TABLE users_ticket_systems
            DROP refresh_token,
            DROP token_expires_at,
            MODIFY tokensecret TEXT NOT NULL');
    }

    public function isTransactional(): bool
    {
        return true;
    }
}
