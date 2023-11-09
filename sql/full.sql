SET NAMES 'utf8';

DROP TABLE IF EXISTS `contracts`;
DROP TABLE IF EXISTS `entries`;
DROP TABLE IF EXISTS `presets`;
DROP TABLE IF EXISTS `teams_users`;
DROP TABLE IF EXISTS `teams_customers`;
DROP TABLE IF EXISTS `projects`;
DROP TABLE IF EXISTS `tickets`;
DROP TABLE IF EXISTS `users_ticket_systems`;
DROP TABLE IF EXISTS `ticket_systems`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `teams`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `activities`;
DROP TABLE IF EXISTS `accounts`;
DROP TABLE IF EXISTS `holidays`;

DROP VIEW IF EXISTS `v_DatenexportDieserMonat`;
DROP VIEW IF EXISTS `v_DatenexportLetzterMonat`;
DROP VIEW IF EXISTS `v_Datenexport`;

DROP VIEW IF EXISTS `v_ProjekteProTagJeMonatUndTeam`;
DROP VIEW IF EXISTS `v_ProjekteProTagJeMonat`;
DROP VIEW IF EXISTS `v_ProjekteProTag`;
DROP VIEW IF EXISTS `v_DistinkteTagesProjekte`;
DROP VIEW IF EXISTS `v_Projektsummen`;



--
-- Tabellenstruktur für Tabelle `users`
--
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `abbr` char(3) DEFAULT NULL,
  `type` varchar(255) NOT NULL,
  `jira_token` varchar(64) DEFAULT NULL,
  `show_empty_line` tinyint(1) NOT NULL DEFAULT '0',
  `suggest_time` tinyint(1) NOT NULL DEFAULT '1',
  `show_future` tinyint(1) NOT NULL DEFAULT '1',
  `locale` char(2) NOT NULL DEFAULT 'de',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;


--
-- Tabellenstruktur für Tabelle `teams`
--
CREATE TABLE `teams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(31) NOT NULL,
  `lead_user_id` INT(11) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

ALTER TABLE `teams`
  ADD CONSTRAINT `teams_ifbk1` FOREIGN KEY (`lead_user_id`) REFERENCES `users` (`id`);


--
-- Tabellenstruktur für Tabelle `teams_users`
--
CREATE TABLE `teams_users`
(
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `team_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY (`user_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

ALTER TABLE `teams_users`
  ADD CONSTRAINT `teams_users_ifbk1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`),
  ADD CONSTRAINT `teams_users_ifbk2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;


--
-- Tabellenstruktur für Tabelle `accounts`
--
CREATE TABLE `accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;


--
-- Tabellenstruktur für Tabelle `activities`
--
CREATE TABLE `activities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `needs_ticket` tinyint(1) NOT NULL default '0',
  `factor` float UNSIGNED NOT NULL default '1.000',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;


--
-- Tabellenstruktur für Tabelle `customers`
--
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `active` int(1) unsigned NOT NULL default '0',
  `global` int(1) unsigned NOT NULL default '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;


--
-- Tabellenstruktur für Tabelle `ticket_systems`
--
CREATE TABLE `ticket_systems` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(31) NOT NULL,
  `type` varchar(15) NOT NULL,
  `book_time` tinyint(1) NOT NULL DEFAULT '0',
  `url` varchar(255) NOT NULL,
  `login` varchar(63) NOT NULL,
  `password` varchar(63) NOT NULL,
  `public_key` text NOT NULL,
  `private_key` text NOT NULL,
  `ticketurl` VARCHAR(255) NOT NULL,
  `oauth_consumer_key` VARCHAR(100) NULL,
  `oauth_consumer_secret` VARCHAR(4000) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;


--
-- Tabellenstruktur für Tabelle `tickets`
--
CREATE TABLE `tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_system_id` int(11) NOT NULL,
  `ticket_number` varchar(31) NOT NULL,
  `name` varchar(127) NOT NULL,
  `estimation` int(11) NULL,
  `parent` varchar(31) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`ticket_system_id`, `ticket_number`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

ALTER TABLE `tickets`
  ADD CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`ticket_system_id`) REFERENCES `ticket_systems` (`id`);


--
-- Tabellenstruktur für Tabelle `projects`
--
CREATE TABLE `projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) DEFAULT NULL,
  `name` varchar(127) NOT NULL,
  `jira_id` varchar(63) DEFAULT NULL,
  `jira_ticket` VARCHAR(63) NULL,
  `ticket_system` int(11) NULL DEFAULT NULL,
  `active` tinyint(1) NOT NULL,
  `global` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `estimation` int(11) NULL,
  `offer` varchar(31) NULL,
  `billing` tinyint NOT NULL DEFAULT '0',
  `cost_center` varchar(31) NULL,
  `internal_ref` varchar(31) NULL,
  `external_ref` varchar(31) NULL,
  `project_lead_id` int(11) DEFAULT NULL,
  `technical_lead_id` int(11) DEFAULT NULL,
  `invoice` varchar(31) DEFAULT NULL,
  `additional_information_from_external` tinyint(1) NOT NULL,
  `internal_jira_project_key` VARCHAR(50) NULL,
  `internal_jira_ticket_system` INTEGER(11) NULL,
  `subtickets` TEXT DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

ALTER TABLE `projects`
  ADD CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `projects_ibfk_2` FOREIGN KEY (`ticket_system`) REFERENCES `ticket_systems` (`id`),
  ADD CONSTRAINT `projects_ifbk_3` FOREIGN KEY (`project_lead_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `projects_ifbk_4` FOREIGN KEY (`technical_lead_id`) REFERENCES `users` (`id`);


--
-- Tabellenstruktur für Tabelle `teams_customers`
--
CREATE TABLE `teams_customers`
(
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `team_id` INT(11) NOT NULL,
  `customer_id` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

ALTER TABLE `teams_customers`
  ADD CONSTRAINT `teams_customers_ifbk1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`),
  ADD CONSTRAINT `teams_customers_ifbk2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;


--
-- Tabellenstruktur für Tabelle `entries`
--
CREATE TABLE `entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `day` date NOT NULL,
  `start` time NOT NULL,
  `end` time NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `activity_id` int(11) DEFAULT NULL,
  `ticket` varchar(32) NOT NULL,
  `worklog_id` int(11) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `duration` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `class` tinyint unsigned NOT NULL DEFAULT '0',
  `synced_to_ticketsystem` TINYINT(1) DEFAULT 0 NULL,
  `internal_jira_ticket_original_key` VARCHAR(50) NULL,
  PRIMARY KEY (`id`),
  KEY (`project_id`),
  KEY (`user_id`),
  KEY (`account_id`),
  KEY (`activity_id`),
  KEY (`customer_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

--
-- Constraints der Tabelle `entries`
--
ALTER TABLE `entries`
  ADD CONSTRAINT `entries_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  ADD CONSTRAINT `entries_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `entries_ibfk_3` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `entries_ibfk_4` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`),
  ADD CONSTRAINT `entries_ibfk_5` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);


--
-- Tabellenstruktur für Tabelle `presets`
--
CREATE TABLE `presets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `activity_id` int(11) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

ALTER TABLE `presets`
  ADD CONSTRAINT `presets_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `presets_ibfk_2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  ADD CONSTRAINT `presets_ibfk_3` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`);


--
-- Tabellenstruktur für Tabelle `holidays`
--
CREATE TABLE `holidays` (
  `day` date NOT NULL PRIMARY KEY,
  `name` varchar(31) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


--
-- Tabellenstruktur für Tabelle `users_ticket_systems`
--
CREATE TABLE `users_ticket_systems` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `ticket_system_id` int(11) NOT NULL,
  `accesstoken` varchar(50) NOT NULL,
  `tokensecret` varchar(50) NOT NULL,
  `avoidconnection` TINYINT(1) unsigned DEFAULT '0' NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_user_id_idx` (`user_id`),
  KEY `fk_ticket_system_id_idx` (`ticket_system_id`),
  CONSTRAINT `fk_ticket_system_id` FOREIGN KEY (`ticket_system_id`) REFERENCES `ticket_systems` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `fk_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


--
-- Tabellenstruktur für Tabelle `contracts`
--
CREATE TABLE `contracts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `start` date NULL,
  `end` date NULL,
  `hours_0` float not null default '0.0',
  `hours_1` float not null default '8.0',
  `hours_2` float not null default '8.0',
  `hours_3` float not null default '8.0',
  `hours_4` float not null default '8.0',
  `hours_5` float not null default '8.0',
  `hours_6` float not null default '0.0',
  PRIMARY KEY (`id`),
  KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Arbeitsvertrag: Stunden pro Woche/Tag';


-- EXPORT-VIEWS ---------------------------------------------------------------------------

CREATE VIEW `v_Datenexport` AS
SELECT e.id, e.day AS Datum, e.start AS Start, e.end AS Ende, u.username AS Mitarbeiter
  , c.name AS Kunde, p.name AS Projekt, a.name AS `Tätigkeit`
  , e.description AS Beschreibung, e.ticket AS Fall
  , SEC_TO_TIME(ABS(e.duration * 60)) AS Dauer
  , 'x' AS `JIRA-Buchung`
FROM entries e
LEFT JOIN users u
  ON u.id = e.user_id
LEFT JOIN customers c
  ON c.id = e.customer_id
LEFT JOIN projects p
  ON p.id = e.project_id
LEFT JOIN activities a
  ON a.id= e.activity_id
ORDER BY u.id ASC, e.day ASC, e.start ASC;

CREATE VIEW `v_DatenexportDieserMonat` AS
SELECT * FROM v_Datenexport
WHERE Datum >= DATE_FORMAT(CURDATE(), "%Y-%m-01");

CREATE VIEW `v_DatenexportLetzterMonat` AS
SELECT * FROM v_Datenexport
WHERE Datum >= DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL -1 MONTH), "%Y-%m-01")
AND Datum < DATE_FORMAT(CURDATE(), "%Y-%m-01");



-- AUSWERTUNGEN --------------------------------------------------------------------------

-- Hier zaehlen die PLs mit rein, da sie natuerlich auch Stunden verursachen
CREATE VIEW `v_Projektsummen` AS
SELECT YEAR(e.day) AS Jahr, MONTH(e.day) AS Monat, t.name AS Team
  , c.name AS Kunde, p.name AS Projekt
  , SEC_TO_TIME(SUM(ABS(e.duration * 60))) AS Gesamtdauer
FROM entries e
LEFT JOIN users u ON u.id = e.user_id
LEFT JOIN teams_users tu ON tu.user_id = u.id
LEFT JOIN teams t ON t.id=tu.team_id
LEFT JOIN customers c ON c.id=e.customer_id
LEFT JOIN projects p ON p.id=e.project_id
WHERE (u.type='DEV' OR u.id = t.lead_user_id)
GROUP BY YEAR(e.day), MONTH(e.day), e.customer_id, e.project_id, t.id;


CREATE VIEW `v_DistinkteTagesProjekte` AS
SELECT DISTINCT e.user_id, e.day, e.customer_id, e.project_id
FROM entries e
WHERE e.activity_id NOT IN (0, 9, 23, 25, 26,30,31,32,35);

CREATE VIEW `v_ProjekteProTag` AS
SELECT a.day AS Tag, u.username AS Mitarbeiter, COUNT(*) AS Projekte
FROM v_DistinkteTagesProjekte a
LEFT JOIN users u ON u.id=a.user_id
WHERE u.id IS NOT NULL
GROUP BY a.user_id, a.day;

CREATE VIEW `v_ProjekteProTagJeMonat` AS
SELECT YEAR(b.Tag) AS Jahr, MONTH(b.Tag) AS Monat, b.Mitarbeiter, AVG(b.Projekte) AS Projekte
FROM v_ProjekteProTag b
GROUP BY b.Mitarbeiter, YEAR(b.Tag), MONTH(b.Tag)
ORDER BY Jahr DESC, Monat DESC, Mitarbeiter ASC;

-- Hier zaehlen die PLs nicht mit rein, da sie einen anderen Taetigkeitsbereich haben
CREATE VIEW `v_ProjekteProTagJeMonatUndTeam` AS
SELECT t.name AS Team, Jahr,Monat, AVG(Projekte) FROM `v_ProjekteProTagJeMonat` m
LEFT JOIN users u ON m.Mitarbeiter=u.username
LEFT JOIN teams_users tu ON tu.user_id=u.id
LEFT JOIN teams t ON t.id=tu.team_id
WHERE u.type = 'DEV'
GROUP BY Team, Jahr,Monat ORDER BY Jahr ASC, Monat ASC, Team ASC;
