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
 * Passkeys / WebAuthn credential storage (ADR-018 D3).
 *
 * `webauthn_credentials` persists a registered passkey (columns map to the
 * bundle's Webauthn\CredentialRecord mapped-superclass). `users.webauthn_user_handle`
 * is the stable, non-enumerable handle a passkey is bound to — NULL until the
 * account registers its first passkey, so no existing row is affected on upgrade.
 */
final class Version20260703_AddWebauthnCredentials extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add webauthn_credentials table and users.webauthn_user_handle for passkeys';
    }

    public function up(Schema $schema): void
    {
        // KEY on user_handle: the credential list query filters by it. (No FK — the
        // handle is the WebAuthn identity, decoupled from the users PK on purpose.)
        $this->addSql('CREATE TABLE webauthn_credentials (public_key_credential_id LONGTEXT NOT NULL, type VARCHAR(255) NOT NULL, transports JSON NOT NULL, attestation_type VARCHAR(255) NOT NULL, trust_path JSON NOT NULL, aaguid TINYTEXT NOT NULL, credential_public_key LONGTEXT NOT NULL, user_handle VARCHAR(255) NOT NULL, counter INT NOT NULL, other_ui JSON DEFAULT NULL, backup_eligible TINYINT DEFAULT NULL, backup_status TINYINT DEFAULT NULL, uv_initialized TINYINT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, INDEX IDX_webauthn_user_handle (user_handle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        // One handle per user (multiple NULLs allowed — a unique index treats NULLs
        // as distinct), indexed for the per-assertion findOneByUserHandle lookup.
        $this->addSql('ALTER TABLE users ADD webauthn_user_handle VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_users_webauthn_user_handle ON users (webauthn_user_handle)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_users_webauthn_user_handle ON users');
        $this->addSql('ALTER TABLE users DROP COLUMN webauthn_user_handle');
        $this->addSql('DROP TABLE webauthn_credentials');
    }
}
