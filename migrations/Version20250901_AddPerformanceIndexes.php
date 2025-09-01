<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add performance indexes for frequently queried fields in entries table.
 * These indexes significantly improve query performance for common operations.
 */
final class Version20250901_AddPerformanceIndexes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance indexes for entries table to optimize common queries';
    }

    public function up(Schema $schema): void
    {
        // Composite index for user + date queries (most common)
        $this->addSql('CREATE INDEX idx_entries_user_day ON entries (user_id, day DESC)');
        
        // Index for date range queries
        $this->addSql('CREATE INDEX idx_entries_day ON entries (day)');
        
        // Index for customer queries
        $this->addSql('CREATE INDEX idx_entries_customer ON entries (customer_id)');
        
        // Index for project queries
        $this->addSql('CREATE INDEX idx_entries_project ON entries (project_id)');
        
        // Index for activity queries
        $this->addSql('CREATE INDEX idx_entries_activity ON entries (activity_id)');
        
        // Index for ticket queries
        $this->addSql('CREATE INDEX idx_entries_ticket ON entries (ticket)');
        
        // Composite index for user + project (common in reports)
        $this->addSql('CREATE INDEX idx_entries_user_project ON entries (user_id, project_id)');
        
        // Composite index for sync status queries
        $this->addSql('CREATE INDEX idx_entries_user_sync ON entries (user_id, synced_to_ticketsystem)');
        
        // Index for worklog_id lookups
        $this->addSql('CREATE INDEX idx_entries_worklog ON entries (worklog_id)');
        
        // Composite index for date + start time sorting
        $this->addSql('CREATE INDEX idx_entries_day_start ON entries (day DESC, start DESC)');
    }

    public function down(Schema $schema): void
    {
        // Remove all indexes
        $this->addSql('DROP INDEX idx_entries_user_day ON entries');
        $this->addSql('DROP INDEX idx_entries_day ON entries');
        $this->addSql('DROP INDEX idx_entries_customer ON entries');
        $this->addSql('DROP INDEX idx_entries_project ON entries');
        $this->addSql('DROP INDEX idx_entries_activity ON entries');
        $this->addSql('DROP INDEX idx_entries_ticket ON entries');
        $this->addSql('DROP INDEX idx_entries_user_project ON entries');
        $this->addSql('DROP INDEX idx_entries_user_sync ON entries');
        $this->addSql('DROP INDEX idx_entries_worklog ON entries');
        $this->addSql('DROP INDEX idx_entries_day_start ON entries');
    }
    
    public function isTransactional(): bool
    {
        return true;
    }
}