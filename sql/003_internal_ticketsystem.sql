ALTER TABLE `projects` ADD COLUMN `internal_jira_project_key` VARCHAR(50) NULL AFTER `additional_information_from_external`;
ALTER TABLE `projects` ADD COLUMN `internal_jira_ticket_system` INTEGER(11) NULL AFTER `internal_jira_project_key`;
ALTER TABLE `entries` ADD COLUMN `internal_jira_ticket_original_key` VARCHAR(50) NULL AFTER `synced_to_ticketsystem`;