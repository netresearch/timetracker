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
 * Add `customers.tempo_customer_key` (ADR-026 P2): a nullable, UNIQUE stable key
 * so the Tempo->Customer mapping is idempotent across import runs — a re-import
 * or a P3 auto-create resolves the existing Customer by key instead of spawning
 * a duplicate on name drift. NULL (default) for every existing customer; the
 * UNIQUE index tolerates multiple NULLs (MySQL/MariaDB), so no default is needed.
 */
final class Version20260712_CustomerTempoKey extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable UNIQUE customers.tempo_customer_key for idempotent Tempo->Customer upserts (ADR-026 P2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customers ADD tempo_customer_key VARCHAR(63) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_customers_tempo_customer_key ON customers (tempo_customer_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_customers_tempo_customer_key ON customers');
        $this->addSql('ALTER TABLE customers DROP COLUMN tempo_customer_key');
    }
}
