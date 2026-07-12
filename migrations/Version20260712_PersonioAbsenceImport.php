<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712_PersonioAbsenceImport extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-024 P2: personio_absence_import table (Personio absence id -> created TT entry ids + signature)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE personio_absence_import (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                last_sync_run_id INT DEFAULT NULL,
                absence_id VARCHAR(191) NOT NULL,
                entry_ids JSON NOT NULL,
                signature JSON NOT NULL,
                last_imported_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_personio_absence_id (absence_id),
                INDEX idx_personio_absence_user (user_id),
                PRIMARY KEY (id),
                CONSTRAINT fk_personio_absence_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_personio_absence_run FOREIGN KEY (last_sync_run_id) REFERENCES sync_runs (id) ON DELETE SET NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE personio_absence_import');
    }
}
