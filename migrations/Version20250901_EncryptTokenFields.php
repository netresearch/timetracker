<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to expand token fields for encrypted storage
 */
final class Version20250901_EncryptTokenFields extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expand OAuth token fields from VARCHAR(50) to TEXT to support encrypted token storage';
    }

    public function up(Schema $schema): void
    {
        // Change accesstoken and tokensecret columns from VARCHAR(50) to TEXT
        $this->addSql('ALTER TABLE users_ticket_systems MODIFY COLUMN accesstoken TEXT NOT NULL');
        $this->addSql('ALTER TABLE users_ticket_systems MODIFY COLUMN tokensecret TEXT NOT NULL');
        
        // Add index for performance on user_id since we query by user frequently
        $this->addSql('CREATE INDEX idx_user_ticket_system_user ON users_ticket_systems (user_id)');
    }

    public function down(Schema $schema): void
    {
        // Note: This will fail if encrypted tokens are longer than 50 chars
        // Manual intervention required to decrypt tokens before downgrade
        $this->addSql('ALTER TABLE users_ticket_systems MODIFY COLUMN accesstoken VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE users_ticket_systems MODIFY COLUMN tokensecret VARCHAR(50) NOT NULL');
        
        // Remove the index
        $this->addSql('DROP INDEX idx_user_ticket_system_user ON users_ticket_systems');
    }
    
    public function isTransactional(): bool
    {
        return true;
    }
}