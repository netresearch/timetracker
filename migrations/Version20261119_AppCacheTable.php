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
 * Create the table that backs the Symfony app cache (ADR-028).
 *
 * `cache.app` moves from APCu to `cache.adapter.doctrine_dbal`. The columns
 * match what Symfony's DoctrineDbalAdapter creates on MySQL/MariaDB
 * (DoctrineDbalAdapter::buildSchemaTable(): binary(255) id, blob of 16777215
 * bytes for the data, two unsigned integers), so `doctrine:schema:validate`
 * sees no difference. IF NOT EXISTS because the adapter creates the table on
 * its own when a write finds it missing, so an instance may already have it.
 */
final class Version20261119_AppCacheTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cache_items for the Doctrine DBAL app cache that replaces APCu';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS cache_items (item_id VARBINARY(255) NOT NULL, item_data MEDIUMBLOB NOT NULL, item_lifetime INT UNSIGNED DEFAULT NULL, item_time INT UNSIGNED NOT NULL, PRIMARY KEY (item_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS cache_items');
    }
}
