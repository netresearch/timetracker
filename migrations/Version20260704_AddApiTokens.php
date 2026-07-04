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
 * Add the `api_tokens` table for user-bound Personal Access Tokens (ADR-021).
 *
 * Only the SHA-256 hash of a token is stored (`token_hash`, unique). Scopes are a
 * JSON array. A token is active while `revoked_at` is NULL and `expires_at` is
 * NULL or in the future. Additive: no existing table changes.
 */
final class Version20260704_AddApiTokens extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add api_tokens table for user-bound API personal access tokens (ADR-021)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE api_tokens (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                name VARCHAR(100) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                scopes JSON NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                last_used_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_api_token_hash (token_hash),
                INDEX idx_api_token_user (user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE api_tokens ADD CONSTRAINT FK_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_tokens');
    }
}
