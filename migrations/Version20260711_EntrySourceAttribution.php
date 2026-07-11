<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711_EntrySourceAttribution extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-025: agent vs human time attribution on entries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE entries
            ADD source VARCHAR(8) DEFAULT 'human' NOT NULL,
            ADD logged_by_id INT DEFAULT NULL,
            ADD estimated TINYINT(1) DEFAULT 0 NOT NULL,
            ADD responsible_user_id INT DEFAULT NULL,
            ADD touchpoints JSON DEFAULT NULL");
        $this->addSql('ALTER TABLE entries
            ADD CONSTRAINT FK_entries_logged_by FOREIGN KEY (logged_by_id) REFERENCES users (id) ON DELETE SET NULL,
            ADD CONSTRAINT FK_entries_responsible FOREIGN KEY (responsible_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_entries_source ON entries (source)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entries DROP FOREIGN KEY FK_entries_logged_by, DROP FOREIGN KEY FK_entries_responsible');
        $this->addSql('DROP INDEX IDX_entries_source ON entries');
        $this->addSql('ALTER TABLE entries DROP source, DROP logged_by_id, DROP estimated, DROP responsible_user_id, DROP touchpoints');
    }
}
