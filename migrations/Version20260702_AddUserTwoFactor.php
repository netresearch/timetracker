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
 * Add TOTP two-factor columns to `users` (ADR-018 D2).
 *
 * - `totp_secret`: the RFC-6238 shared secret, stored **encrypted at rest**
 *   (AES-256-GCM via TokenEncryptionService — same as Jira OAuth tokens, ADR-017).
 *   NULL = TOTP not enrolled.
 * - `backup_codes`: a JSON array of **hashed** one-time recovery codes; NULL/[]
 *   when none are outstanding.
 *
 * Both default NULL, so every existing account is unaffected — 2FA is strictly
 * opt-in and no login flow changes until a user enrolls.
 */
final class Version20260702_AddUserTwoFactor extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.totp_secret (encrypted) and users.backup_codes (hashed JSON) for TOTP 2FA';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD totp_secret VARCHAR(255) DEFAULT NULL, ADD backup_codes JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN totp_secret, DROP COLUMN backup_codes');
    }
}
