<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711_PersonioAttendanceExport extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-024 P1: personio_configs + personio_attendance_export tables, users personio opt-in/employee-id columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD personio_sync_enabled TINYINT(1) NOT NULL DEFAULT 0, ADD personio_employee_id BIGINT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE personio_configs (
                id INT AUTO_INCREMENT NOT NULL,
                absence_project_id INT DEFAULT NULL,
                name VARCHAR(63) NOT NULL,
                base_url VARCHAR(255) NOT NULL,
                client_id VARCHAR(255) NOT NULL,
                client_secret LONGTEXT NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE INDEX uniq_personio_configs_name (name),
                INDEX idx_personio_configs_absence_project (absence_project_id),
                PRIMARY KEY (id),
                CONSTRAINT fk_personio_configs_project FOREIGN KEY (absence_project_id) REFERENCES projects (id) ON DELETE SET NULL
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE personio_attendance_export (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                last_sync_run_id INT DEFAULT NULL,
                day DATE NOT NULL,
                period_ids JSON NOT NULL,
                base_payload JSON NOT NULL,
                last_exported_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_personio_export_user_day (user_id, day),
                INDEX idx_personio_export_user (user_id),
                PRIMARY KEY (id),
                CONSTRAINT fk_personio_export_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_personio_export_run FOREIGN KEY (last_sync_run_id) REFERENCES sync_runs (id) ON DELETE SET NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE personio_attendance_export');
        $this->addSql('DROP TABLE personio_configs');
        $this->addSql('ALTER TABLE users DROP COLUMN personio_sync_enabled, DROP COLUMN personio_employee_id');
    }
}
