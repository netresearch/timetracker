ALTER TABLE `projects` ADD COLUMN `jira_ticket` VARCHAR(63) NULL AFTER `jira_id`;
ALTER TABLE `projects` ADD COLUMN `subtickets` TEXT DEFAULT '' AFTER `internal_jira_ticket_system`;

