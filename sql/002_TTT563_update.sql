ALTER TABLE `ticket_systems` ADD COLUMN `ticketurl` VARCHAR(255) NULL;
ALTER TABLE `ticket_systems` ADD COLUMN `ticketurl` VARCHAR(255) NULL;
UPDATE ticket_systems SET ticketurl = 'https://jira.netresearch.de/browse/%s' WHERE id = 1;
UPDATE ticket_systems SET ticketurl = 'https://jira.aida.de/browse/%s' WHERE id = 2;
INSERT INTO `ticket_systems` (`name`, `type`, `book_time`, `url`, `ticketurl`)
VALUES ('NR Freshdesk', 'FRESHDESK', 0, 'https://netresearch.freshdesk.com', 'https://bugs.nr/%s');