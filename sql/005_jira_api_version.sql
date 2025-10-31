ALTER TABLE `ticket_systems` ADD COLUMN `jira_api_version` VARCHAR(10) NULL DEFAULT '2' AFTER `oauth_consumer_secret`;
