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
 * Add `ticket_systems.auto_import_unresolved_projects` (ADR-026 P3): the opt-in
 * gate for ad-hoc auto-create of unresolved Jira projects during worklog import.
 * Defaults to FALSE so existing systems keep the current park-on-unresolved
 * behaviour untouched — auto-create (which writes billing data) is only ever
 * reached once an admin flips this flag on.
 */
final class Version20260712_AutoImportProjectsFlag extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add opt-in ticket_systems.auto_import_unresolved_projects flag for ADR-026 P3 ad-hoc project auto-create';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_systems ADD auto_import_unresolved_projects TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_systems DROP COLUMN auto_import_unresolved_projects');
    }
}
