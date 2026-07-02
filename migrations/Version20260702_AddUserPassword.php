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
 * Add the nullable `users.password` hash for local password accounts (ADR-018 D1).
 *
 * NULL (default) = LDAP account: the credential check is the LDAP bind, unchanged.
 * A stored Symfony `auto` hash = local account: the credential check is the hash,
 * and LDAP is never consulted for that user. No account is affected on upgrade —
 * every existing row stays NULL and keeps authenticating via LDAP.
 */
final class Version20260702_AddUserPassword extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable users.password hash for local (non-LDAP) accounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD password VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN password');
    }
}
