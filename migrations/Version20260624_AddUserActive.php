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
 * Add the `users.active` flag.
 *
 * Lets an account be deactivated (ex-employees): a deactivated user can no longer
 * log in (see App\Security\UserChecker) and is no longer offered for new project /
 * technical-lead assignments, while their historical entries stay intact. Existing
 * users default to active.
 */
final class Version20260624_AddUserActive extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the users.active flag (default 1) for account deactivation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD active TINYINT(1) NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN active');
    }
}
