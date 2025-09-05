-- phpMyAdmin SQL Dump
-- version 4.5.5.1
-- http://www.phpmyadmin.net
--
-- Host: db_unittest:3306
-- Erstellungszeit: 08. Jun 2018 um 10:51
-- Server-Version: 10.1.25-MariaDB-1~jessie
-- PHP-Version: 7.0.20

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


--
-- Datenbank: `db_unittest`
--

--
-- Tabellenstruktur für Tabelle `accounts`
--
DROP TABLE IF EXISTS `accounts`;
CREATE TABLE `accounts` (
  `id` int(11) NOT NULL,
  `account_name` varchar(250) NOT NULL,
  `internal_jira_project_key` varchar(255) DEFAULT '',
  `internal_jira_issue_number` varchar(255) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `activities`
--

DROP TABLE IF EXISTS `activities`;
CREATE TABLE `activities` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `needs_ticket` tinyint(1) NOT NULL DEFAULT '0',
  `factor` decimal(10,1) NOT NULL DEFAULT '1.0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Daten für Tabelle `activities`
--

INSERT INTO `activities` (`id`, `name`, `needs_ticket`, `factor`) VALUES
(1, 'Arbeiten', 0, '1.0'),
(2, 'Reisen', 0, '2.0'),
(3, 'Testen', 0, '1.0'),
(4, 'Testing', 0, '1.0');

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `contracts`
--

DROP TABLE IF EXISTS `contracts`;
CREATE TABLE `contracts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `start` date NOT NULL,
  `end` date DEFAULT NULL,
  `hours_0` decimal(3,1) NOT NULL DEFAULT '0.0',
  `hours_1` decimal(3,1) NOT NULL DEFAULT '0.0',
  `hours_2` decimal(3,1) NOT NULL DEFAULT '0.0',
  `hours_3` decimal(3,1) NOT NULL DEFAULT '0.0',
  `hours_4` decimal(3,1) NOT NULL DEFAULT '0.0',
  `hours_5` decimal(3,1) NOT NULL DEFAULT '0.0',
  `hours_6` decimal(3,1) NOT NULL DEFAULT '0.0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Daten für Tabelle `contracts`
--

INSERT INTO `contracts` (`id`, `user_id`, `start`, `end`, `hours_0`, `hours_1`, `hours_2`, `hours_3`, `hours_4`, `hours_5`, `hours_6`) VALUES
(1, 1, '2020-02-01', '2020-01-31', '0.0', '1.0', '2.0', '3.0', '4.0', '5.0', '0.0'),
(2, 1, '2020-02-01', NULL, '0.0', '1.1', '2.2', '3.3', '4.4', '5.5', '0.5'),
(3, 2, '1020-01-01', '2020-01-01', '1.0', '1.0', '1.0', '1.0', '1.0', '1.0', '1.0'),
(4, 3, '0700-01-01', NULL, '1.0', '2.0', '3.0', '4.0', '5.0', '5.0', '5.0');

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `customers`
--

DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `global` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Daten für Tabelle `customers`
--

INSERT INTO `customers` (`id`, `name`, `active`, `global`) VALUES
(1, 'Der Bäcker von nebenan', 1, 0),
(2, 'Der Globale Customer', 1, 1),
(3, 'Der nebenan vom Bäcker', 1, 0);

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `entries`
--

DROP TABLE IF EXISTS `entries`;
CREATE TABLE `entries` (
  `id` int(11) NOT NULL,
  `day` date NOT NULL,
  `start` time NOT NULL DEFAULT '00:00:00',
  `end` time NOT NULL DEFAULT '00:00:00',
  `customer_id` int(11) NOT NULL DEFAULT '1',
  `project_id` int(11) NOT NULL DEFAULT '1',
  `activity_id` int(11) NOT NULL DEFAULT '1',
  `account_id` int(11) DEFAULT NULL,
  `description` text,
  `ticket` varchar(255) DEFAULT NULL,
  `worklog_id` int(11) DEFAULT NULL,
  `duration` decimal(5,2) NOT NULL DEFAULT '0.00',
  `user_id` int(11) NOT NULL DEFAULT '1',
  `class` int(11) NOT NULL DEFAULT '1',
  `synced_to_ticketsystem` tinyint(1) NOT NULL DEFAULT '0',
  `internal_jira_ticket_original_key` varchar(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Daten für Tabelle `entries`
--

INSERT INTO `entries` (`id`, `day`, `start`, `end`, `customer_id`, `project_id`, `activity_id`, `account_id`, `description`, `ticket`, `worklog_id`, `duration`, `user_id`, `class`, `synced_to_ticketsystem`, `internal_jira_ticket_original_key`) VALUES
(1, '2018-06-01', '08:00:00', '12:00:00', 1, 1, 1, NULL, 'Backen', '12312', NULL, '4.00', 1, 1, 0, ''),
(2, '2018-06-01', '13:00:00', '17:00:00', 2, 2, 2, NULL, 'Kneten', '12313', NULL, '4.00', 2, 1, 0, ''),
(3, '2018-06-02', '08:00:00', '12:00:00', 3, 3, 3, NULL, 'Testen', '12314', NULL, '4.00', 1, 1, 0, '');

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `holidays`
--

DROP TABLE IF EXISTS `holidays`;
CREATE TABLE `holidays` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `day` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Daten für Tabelle `holidays`
--

INSERT INTO `holidays` (`id`, `name`, `day`) VALUES
(1, 'Neujahr', '2018-01-01');

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `presets`
--

DROP TABLE IF EXISTS `presets`;
CREATE TABLE `presets` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `description` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Daten für Tabelle `presets`
--

INSERT INTO `presets` (`id`, `name`, `customer_id`, `project_id`, `activity_id`, `description`) VALUES
(1, 'Backen', 1, 1, 1, 'Testbeschreibung'),
(2, 'Kneten', 2, 2, 2, 'Testbeschreibung'),
(3, 'Testen', 3, 3, 3, 'Testbeschreibung');

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `projects`
--

DROP TABLE IF EXISTS `projects`;
CREATE TABLE `projects` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `jira_id` varchar(255) DEFAULT NULL,
  `ticket_system` int(11) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `global` tinyint(1) NOT NULL DEFAULT '0',
  `estimation` decimal(10,2) NOT NULL DEFAULT '0.00',
  `offer` varchar(255) DEFAULT NULL,
  `billing` int(11) DEFAULT NULL,
  `cost_center` varchar(255) DEFAULT NULL,
  `internal_ref` varchar(255) DEFAULT NULL,
  `external_ref` varchar(255) DEFAULT NULL,
  `project_lead_id` int(11) DEFAULT NULL,
  `technical_lead_id` int(11) DEFAULT NULL,
  `invoice` int(11) DEFAULT NULL,
  `additional_information_from_external` tinyint(1) NOT NULL DEFAULT '0',
  `internal_jira_project_key` varchar(255) NOT NULL DEFAULT '',
  `internal_jira_ticket_system` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Daten für Tabelle `projects`
--

INSERT INTO `projects` (`id`, `customer_id`, `name`, `jira_id`, `ticket_system`, `active`, `global`, `estimation`, `offer`, `billing`, `cost_center`, `internal_ref`, `external_ref`, `project_lead_id`, `technical_lead_id`, `invoice`, `additional_information_from_external`, `internal_jira_project_key`, `internal_jira_ticket_system`) VALUES
(1, 1, 'Backen', 'BA', NULL, 1, 0, '0.00', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, 0, '', 0),
(2, 2, 'Kneten', 'KN', NULL, 1, 0, '0.00', NULL, NULL, NULL, NULL, NULL, 2, 2, NULL, 0, '', 0),
(3, 3, 'Hacken', 'HA', NULL, 1, 0, '0.00', NULL, NULL, NULL, NULL, NULL, 3, 3, NULL, 0, '', 0);

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `teams`
--

DROP TABLE IF EXISTS `teams`;
CREATE TABLE `teams` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `lead_user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Daten für Tabelle `teams`
--

INSERT INTO `teams` (`id`, `name`, `lead_user_id`) VALUES
(1, 'Hackerman', 2),
(2, 'Kuchenbäcker', 1);

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `teams_customers`
--

DROP TABLE IF EXISTS `teams_customers`;
CREATE TABLE `teams_customers` (
  `id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Daten für Tabelle `teams_customers`
--

INSERT INTO `teams_customers` (`id`, `team_id`, `customer_id`) VALUES
(1, 1, 1),
(2, 2, 3);

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `teams_users`
--

DROP TABLE IF EXISTS `teams_users`;
CREATE TABLE `teams_users` (
  `id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Daten für Tabelle `teams_users`
--

INSERT INTO `teams_users` (`id`, `team_id`, `user_id`) VALUES
(1, 1, 2),
(2, 2, 1);

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `ticket_systems`
--

DROP TABLE IF EXISTS `ticket_systems`;
CREATE TABLE `ticket_systems` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` varchar(255) DEFAULT NULL,
  `book_time` tinyint(1) NOT NULL DEFAULT '0',
  `url` varchar(255) DEFAULT NULL,
  `login` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `publicKey` text,
  `privateKey` text,
  `ticketUrl` text,
  `active` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Daten für Tabelle `ticket_systems`
--

INSERT INTO `ticket_systems` (`id`, `name`, `type`, `book_time`, `url`, `login`, `password`, `publicKey`, `privateKey`, `ticketUrl`, `active`) VALUES
(1, 'testSystem', '', 0, '', '', '', '', '', '', 1);

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `tickets`
--

DROP TABLE IF EXISTS `tickets`;
CREATE TABLE `tickets` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `ticket_system_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `ticket_id` varchar(255) NOT NULL,
  `ticket_url` varchar(255) DEFAULT NULL,
  `estimation` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(255) NOT NULL,
  `abbr` varchar(10) NOT NULL DEFAULT '',
  `type` enum('PL','DEV','ADMIN') NOT NULL DEFAULT 'DEV',
  `jira_token` text,
  `show_empty_line` tinyint(1) NOT NULL DEFAULT '0',
  `suggest_time` tinyint(1) NOT NULL DEFAULT '1',
  `show_future` tinyint(1) NOT NULL DEFAULT '1',
  `locale` varchar(5) NOT NULL DEFAULT 'de'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Daten für Tabelle `users`
--

INSERT INTO `users` (`id`, `username`, `abbr`, `type`, `jira_token`, `show_empty_line`, `suggest_time`, `show_future`, `locale`) VALUES
(1, 'admin', 'ADM', 'ADMIN', NULL, 0, 1, 1, 'de'),
(2, 'developer', 'NPL', 'DEV', NULL, 0, 1, 1, 'de'),
(3, 'unittest', 'UTT', 'PL', NULL, 0, 1, 1, 'de'),
(4, 'i.myself', 'IMY', 'PL', NULL, 0, 1, 1, 'de');

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `user_ticketsystem`
--

DROP TABLE IF EXISTS `user_ticketsystem`;
CREATE TABLE `user_ticketsystem` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ticketsystem_id` int(11) NOT NULL,
  `ticket_url` varchar(255) DEFAULT NULL,
  `username` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `contracts`
--
ALTER TABLE `contracts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_start` (`start`),
  ADD KEY `idx_end` (`end`);

--
-- Indizes für die Tabelle `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `entries`
--
ALTER TABLE `entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_day` (`day`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `fk_entries_customer_id` (`customer_id`),
  ADD KEY `fk_entries_project_id` (`project_id`),
  ADD KEY `fk_entries_activity_id` (`activity_id`),
  ADD KEY `fk_entries_account_id` (`account_id`);

--
-- Indizes für die Tabelle `holidays`
--
ALTER TABLE `holidays`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `presets`
--
ALTER TABLE `presets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_presets_customer_id` (`customer_id`),
  ADD KEY `fk_presets_project_id` (`project_id`),
  ADD KEY `fk_presets_activity_id` (`activity_id`);

--
-- Indizes für die Tabelle `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_projects_customer_id` (`customer_id`),
  ADD KEY `fk_projects_project_lead_id` (`project_lead_id`),
  ADD KEY `fk_projects_technical_lead_id` (`technical_lead_id`);

--
-- Indizes für die Tabelle `teams`
--
ALTER TABLE `teams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_teams_lead_user_id` (`lead_user_id`);

--
-- Indizes für die Tabelle `teams_customers`
--
ALTER TABLE `teams_customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_teams_customers_team_id` (`team_id`),
  ADD KEY `fk_teams_customers_customer_id` (`customer_id`);

--
-- Indizes für die Tabelle `teams_users`
--
ALTER TABLE `teams_users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_teams_users_team_id` (`team_id`),
  ADD KEY `fk_teams_users_user_id` (`user_id`);

--
-- Indizes für die Tabelle `ticket_systems`
--
ALTER TABLE `ticket_systems`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_tickets_project_id` (`project_id`),
  ADD KEY `fk_tickets_ticket_system_id` (`ticket_system_id`);

--
-- Indizes für die Tabelle `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indizes für die Tabelle `user_ticketsystem`
--
ALTER TABLE `user_ticketsystem`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_user_ticketsystem_user_id` (`user_id`),
  ADD KEY `fk_user_ticketsystem_ticketsystem_id` (`ticketsystem_id`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT für Tabelle `activities`
--
ALTER TABLE `activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
--
-- AUTO_INCREMENT für Tabelle `contracts`
--
ALTER TABLE `contracts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
--
-- AUTO_INCREMENT für Tabelle `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
--
-- AUTO_INCREMENT für Tabelle `entries`
--
ALTER TABLE `entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
--
-- AUTO_INCREMENT für Tabelle `holidays`
--
ALTER TABLE `holidays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
--
-- AUTO_INCREMENT für Tabelle `presets`
--
ALTER TABLE `presets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
--
-- AUTO_INCREMENT für Tabelle `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
--
-- AUTO_INCREMENT für Tabelle `teams`
--
ALTER TABLE `teams`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
--
-- AUTO_INCREMENT für Tabelle `teams_customers`
--
ALTER TABLE `teams_customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
--
-- AUTO_INCREMENT für Tabelle `teams_users`
--
ALTER TABLE `teams_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
--
-- AUTO_INCREMENT für Tabelle `ticket_systems`
--
ALTER TABLE `ticket_systems`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
--
-- AUTO_INCREMENT für Tabelle `tickets`
--
ALTER TABLE `tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT für Tabelle `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
--
-- AUTO_INCREMENT für Tabelle `user_ticketsystem`
--
ALTER TABLE `user_ticketsystem`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints der exportierten Tabellen
--

--
-- Constraints der Tabelle `contracts`
--
ALTER TABLE `contracts`
  ADD CONSTRAINT `fk_contracts_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `entries`
--
ALTER TABLE `entries`
  ADD CONSTRAINT `fk_entries_account_id` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_entries_activity_id` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_entries_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_entries_project_id` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_entries_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `presets`
--
ALTER TABLE `presets`
  ADD CONSTRAINT `fk_presets_activity_id` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_presets_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_presets_project_id` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `fk_projects_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_projects_project_lead_id` FOREIGN KEY (`project_lead_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_projects_technical_lead_id` FOREIGN KEY (`technical_lead_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints der Tabelle `teams`
--
ALTER TABLE `teams`
  ADD CONSTRAINT `fk_teams_lead_user_id` FOREIGN KEY (`lead_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints der Tabelle `teams_customers`
--
ALTER TABLE `teams_customers`
  ADD CONSTRAINT `fk_teams_customers_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_teams_customers_team_id` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `teams_users`
--
ALTER TABLE `teams_users`
  ADD CONSTRAINT `fk_teams_users_team_id` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_teams_users_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `tickets`
--
ALTER TABLE `tickets`
  ADD CONSTRAINT `fk_tickets_project_id` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tickets_ticket_system_id` FOREIGN KEY (`ticket_system_id`) REFERENCES `ticket_systems` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `user_ticketsystem`
--
ALTER TABLE `user_ticketsystem`
  ADD CONSTRAINT `fk_user_ticketsystem_ticketsystem_id` FOREIGN KEY (`ticketsystem_id`) REFERENCES `ticket_systems` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_ticketsystem_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- Add teams-users relationships for test users
INSERT INTO `teams_users` (`team_id`, `user_id`) VALUES
(1, 4); -- i.myself in Hackerman team

CREATE USER IF NOT EXISTS 'unittest'@'%' IDENTIFIED BY 'unittest';
GRANT ALL PRIVILEGES ON *.* TO 'unittest'@'%';
FLUSH PRIVILEGES;

-- Drop database if exists 
DROP DATABASE IF EXISTS `db_unittest`;

-- Create database
CREATE DATABASE `db_unittest` /*!40100 DEFAULT CHARACTER SET utf8 */;

USE `db_unittest`;

-- Re-create tables (tables were already created above)

-- Disable foreign key checks for data insertion
SET foreign_key_checks = 0;

INSERT INTO `activities` (`id`, `name`, `needs_ticket`, `factor`) VALUES
    (1, 'Backen', 0, 1.0),
    (2, 'Kneten', 0, 2.0),
    (3, 'Verkaufen', 0, 1.5),
    (4, 'Testing', 0, 1.0);

INSERT INTO `customers` (`id`, `name`, `active`, `global`) VALUES
    (1, 'Bäckerei Müller', 1, 0),
    (2, 'Global Customer', 1, 1),
    (3, 'Bäckerei Schmidt', 1, 0);

INSERT INTO `teams` (`id`, `name`, `lead_user_id`) VALUES
    (1, 'Hackerman', 2),
    (2, 'Kuchenbäcker', 1);

INSERT INTO `users` (`id`, `username`, `abbr`, `type`, `jira_token`, `show_empty_line`, `suggest_time`, `show_future`, `locale`) VALUES
    (1, 'admin', 'ADM', 'ADMIN', NULL, 0, 1, 1, 'de'),
    (2, 'developer', 'NPL', 'DEV', NULL, 0, 1, 1, 'de'),
    (3, 'unittest', 'UTT', 'PL', NULL, 0, 1, 1, 'de'),
    (4, 'i.myself', 'IMY', 'PL', NULL, 0, 1, 1, 'de');

INSERT INTO `contracts` (`id`, `user_id`, `start`, `end`, `hours_0`, `hours_1`, `hours_2`, `hours_3`, `hours_4`, `hours_5`, `hours_6`) VALUES
    (1, 1, '2020-02-01', '2020-01-31', 0.0, 1.0, 2.0, 3.0, 4.0, 5.0, 0.0),
    (2, 1, '2020-02-01', NULL, 0.0, 1.1, 2.2, 3.3, 4.4, 5.5, 0.5),
    (3, 2, '1020-01-01', '2020-01-01', 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0),
    (4, 3, '0700-01-01', NULL, 1.0, 2.0, 3.0, 4.0, 5.0, 5.0, 5.0);

INSERT INTO `projects` (`id`, `customer_id`, `name`, `jira_id`, `ticket_system`, `active`, `global`, `estimation`, `offer`, `billing`, `cost_center`, `internal_ref`, `external_ref`, `project_lead_id`, `technical_lead_id`, `invoice`, `additional_information_from_external`, `internal_jira_project_key`, `internal_jira_ticket_system`) VALUES
    (1, 1, 'Backen', 'BA', NULL, 1, 0, 0.00, NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, 0, '', 0),
    (2, 2, 'Kneten', 'KN', NULL, 1, 0, 0.00, NULL, NULL, NULL, NULL, NULL, 2, 2, NULL, 0, '', 0),
    (3, 3, 'Hacken', 'HA', NULL, 1, 0, 0.00, NULL, NULL, NULL, NULL, NULL, 3, 3, NULL, 0, '', 0);

INSERT INTO `presets` (`id`, `name`, `customer_id`, `project_id`, `activity_id`, `description`) VALUES
    (1, 'Backen', 1, 1, 1, 'Testbeschreibung'),
    (2, 'Kneten', 2, 2, 2, 'Testbeschreibung'),
    (3, 'Testen', 3, 3, 3, 'Testbeschreibung');

INSERT INTO `teams_customers` (`id`, `team_id`, `customer_id`) VALUES
    (1, 1, 1),
    (2, 2, 3);

INSERT INTO `teams_users` (`team_id`, `user_id`) VALUES
    (1, 2),
    (2, 1),
    (1, 4); -- i.myself in Hackerman team

INSERT INTO `ticket_systems` (`id`, `name`, `type`, `book_time`, `url`, `login`, `password`, `publicKey`, `privateKey`, `ticketUrl`, `active`) VALUES
    (1, 'testSystem', '', 0, '', '', '', '', '', '', 1);

INSERT INTO `holidays` (`id`, `name`, `day`) VALUES
    (1, 'Neujahr', '2018-01-01');

INSERT INTO `entries` (`id`, `day`, `start`, `end`, `customer_id`, `project_id`, `activity_id`, `account_id`, `description`, `ticket`, `worklog_id`, `duration`, `user_id`, `class`, `synced_to_ticketsystem`, `internal_jira_ticket_original_key`) VALUES
    (1, '2018-06-01', '08:00:00', '12:00:00', 1, 1, 1, NULL, 'Backen', '12312', NULL, 4.00, 1, 1, 0, ''),
    (2, '2018-06-01', '13:00:00', '17:00:00', 2, 2, 2, NULL, 'Kneten', '12313', NULL, 4.00, 2, 1, 0, ''),
    (3, '2018-06-02', '08:00:00', '12:00:00', 3, 3, 3, NULL, 'Testen', '12314', NULL, 4.00, 1, 1, 0, '');

-- Re-enable foreign key checks
SET foreign_key_checks = 1;

-- some test data to get started developing quickly


--
-- activities
--
INSERT INTO `activities` (`id`, `name`, `needs_ticket`, `factor`) VALUES
(1,    'Backen',         0,     1),
(2,    'Kneten',         0,     1),
(3,    'Hacken',         0,     1),
(4,    'Urlauben',       0,     1);


--
-- users
--
INSERT INTO `users` (`id`, `username`, `abbr`, `type`, `jira_token`, `show_empty_line`, `suggest_time`, `show_future`, `locale`) VALUES
(1,    'i.myself',  'IMY',      'PL',   NULL,   0,            1,                 1,      'de'),
(2,    'tony.teamleiter',  'TTE',      'PL',   NULL,   0,            1,                 1,      'de'),
(3,    'sandy.supporter',  'SSU',      'DEV',  NULL,   0,            1,                 1,      'de'),
(4,    'eddy.entwickler',  'EEN',      'DEV',  NULL,   0,            1,                 1,      'de'),
(5,    'harry.hacker',     'HHA',      'DEV',  NULL,   0,            1,                 1,      'de');


--
-- user contracts
--
INSERT INTO `contracts` (`id`, `user_id`, `start`, `end`, `hours_0`, `hours_1`, `hours_2`, `hours_3`, `hours_4`, `hours_5`, `hours_6`) VALUES
(1,    5,   '2020-01-01',      NULL,      0,       8,     8,         8,         8,         8,         0),
(2,    4,   '2020-01-01',      NULL,      0,       7,     7,         7,         7,         7,         0),
(3,    3,   '2020-01-01',      NULL,      0,       8,     8,         8,         8,         6,         0),
(4,    2,   '2020-01-01',      NULL,      0,       6,     6,         6,         6,         4,         0);


--
-- teams
--
INSERT INTO `teams` (`id`, `name`, `lead_user_id`) VALUES
(1,    'Kuchenbäcker',     2),
(2,    'Hacker',           2);


--
-- users-to-teams
--
INSERT INTO `teams_users` (`id`, `team_id`, `user_id`) VALUES
(2,    1,   3),
(3,    1,   4),
(4,    2,   5),
(5,    1,   2),
(6,    2,   2),
(7,    1,   1),
(8,    2,   1);


--
-- customers
--
INSERT INTO `customers` (`id`, `name`, `active`, `global`) VALUES
(1,    'Der Bäcker von nebenan',       1,        0),
(2,    'Freizeit', 1,  1),
(3,    'Dark Net Society',      1,      0);


--
-- customers-to-teams
--
INSERT INTO `teams_customers` (`id`, `team_id`, `customer_id`) VALUES
(1,    2,   3),
(2,    1,   1);


--
-- projects
--
INSERT INTO `projects` (`id`, `customer_id`, `name`, `jira_id`, `ticket_system`, `active`, `global`, `estimation`, `offer`, `billing`, `cost_center`, `internal_ref`, `external_ref`, `project_lead_id`, `technical_lead_id`, `invoice`, `additional_information_from_external`, `internal_jira_project_key`, `internal_jira_ticket_system`) VALUES
(1,    3,   'Server attack',  'SA',          NULL,   1,         0,               0,        '0',      0,            NULL,    NULL,      NULL,          2,              5,              NULL,              0,                   '',        0),
(2,    1,   'Lebkuchen',      'LK',          NULL,   1,         0,               0,        '0',      0,            NULL,    NULL,      NULL,          2,              3,              NULL,              0,                   '',        0),
(3,    1,   'Donauwelle',     'DW',          NULL,   1,         0,               0,        '0',      0,            NULL,    NULL,      NULL,          2,              3,              NULL,              0,                   '',        0),
(4,    2,   'Frei haben',     'FH',          NULL,   1,         1,               0,        '0',      0,            NULL,    NULL,      NULL,          2,              NULL,           NULL,              0,                   '',        0),
(5,    1,   'Bienenstich',    'BS',          NULL,   1,         0,               0,        '0',      0,            NULL,    NULL,      NULL,          2,              2,              NULL,              0,                   '',        0),
(6,    1,   'Sandkuchen',     'SK',          NULL,   0,         0,               0,        '0',      0,            NULL,    NULL,      NULL,          NULL,           NULL,           NULL,              0,                   '',        0),
(7,    3,   'Phreaking',      'PHR',         NULL,   1,         0,               0,        '0',      0,            NULL,    NULL,      NULL,          2,              5,              NULL,              0,                   '',        0),
(8,    2,   'Urlaub',         '',            NULL,   0,         0,               0,        '0',      0,            NULL,    NULL,      NULL,          NULL,           NULL,           NULL,              0,                   '',        0);


--
-- presets
--
INSERT INTO `presets` (`id`, `name`, `customer_id`, `project_id`, `activity_id`, `description`) VALUES
(1,    'Urlaub',      2,     8,      4,             'Urlaub'),
(2,    'Kindkrank',   2,     4,      4,             'Kindkrank'),
(3,    'Sonderurlaub',       2,      8,             4,  'Sonderurlaub');


--
-- activity entries for first user for today
--
INSERT INTO `entries` (`id`, `day`, `start`, `end`, `customer_id`, `project_id`, `account_id`, `activity_id`, `ticket`, `worklog_id`, `description`, `duration`, `user_id`, `class`, `synced_to_ticketsystem`, `internal_jira_ticket_original_key`) VALUES
(1,    CURDATE(),  '08:00:00',   '08:50:00',     3,             1,            NULL,         3,             'SA-1',   NULL,         'Angriff auf Google',      50,        1,       1,                        0,                                   ''),
(2,    CURDATE(),  '09:00:00',   '10:00:00',     3,             1,            NULL,         3,             'SA-2',   NULL,         'Angriff auf die NSA',     60,        1,       1,                        0,                                   ''),
(3,    CURDATE(),  '07:30:00',   '08:00:00',     1,             2,            NULL,         2,             'LK-12',  NULL,         'Lebkuchen kneten und in den Ofen schieben',   30,                       1,                                   2,  0,      ''),
(4,    CURDATE(),  '08:50:00',   '09:00:00',     1,             2,            NULL,         2,             'LK-12',  NULL,         'Lebkuchen aus dem Ofen holen',   10,          1,                        1,                                   0,  ''),
(5,    CURDATE(),  '10:00:00',   '10:30:00',     2,             4,            NULL,         4,             '',       NULL,         'Powernap',    30, 1,   1,        0,           ''),
(6,    CURDATE(),  '10:30:00',   '12:00:00',     1,             5,            NULL,         2,             'BS-40',  NULL,         'Kuchenboden kneten',   90,       1,           1, 0,      ''),
(7,    CURDATE(),  '12:30:00',   '15:10:00',     3,             7,            NULL,         3,             'PHR-23', NULL,         'Captain Crunch tracken',         160,         1, 4,      0,      '');