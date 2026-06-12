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
 * Align legacy holidays tables with the Holiday entity.
 *
 * Long-lived installations carry a v4-era holidays table that only has the
 * `day` column; the Holiday entity (and GET /getHolidays) require `name` as
 * well. Fresh installations created from the entity metadata already have
 * both columns, so this migration is conditional.
 */
final class Version20260612_FixHolidaysSchema extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the missing name column to legacy holidays tables';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('holidays') && !$schema->getTable('holidays')->hasColumn('name')) {
            $this->addSql("ALTER TABLE holidays ADD name VARCHAR(255) NOT NULL DEFAULT ''");
        }
    }

    public function down(Schema $schema): void
    {
        // No down migration: removing the column would discard holiday names.
        $this->skipIf(true, 'Cannot safely remove the name column.');
    }
}
