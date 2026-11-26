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
 * Add `users.two_factor_version` (ADR-018 D4).
 *
 * A counter that changes whenever the user's set of second factors changes. It is
 * a remember-me signature property next to the password, so such a change
 * invalidates the user's outstanding remember-me cookies. Adding the property
 * changes every signature once: all existing remember-me cookies stop working
 * after the upgrade and users sign in again.
 */
final class Version20261126_AddUserTwoFactorVersion extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.two_factor_version, a remember-me signature property for factor changes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS two_factor_version INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS two_factor_version');
    }
}
