SET FOREIGN_KEY_CHECKS=0;
TRUNCATE TABLE `accounts`;
TRUNCATE TABLE `activities`;
TRUNCATE TABLE `contracts`;
TRUNCATE TABLE `entries`;
TRUNCATE TABLE `holidays`;
TRUNCATE TABLE `presets`;
TRUNCATE TABLE `projects`;
TRUNCATE TABLE `teams`;
TRUNCATE TABLE `customers`;
TRUNCATE TABLE `teams_customers`;
TRUNCATE TABLE `teams_users`;
TRUNCATE TABLE `tickets`;
TRUNCATE TABLE `ticket_systems`;
TRUNCATE TABLE `users`;
TRUNCATE TABLE `users_ticket_systems`;
SET FOREIGN_KEY_CHECKS=1;

--
-- activities
--
INSERT INTO `activities` (`id`, `name`, `needs_ticket`, `factor`) VALUES
(1,    'Backen',         0,     1);

--
-- users
--
INSERT INTO `users` (`id`, `username`, `abbr`, `type`, `jira_token`, `show_empty_line`, `suggest_time`, `show_future`, `locale`) VALUES
(1,   'i.myself',         'IMY',      'PL',    NULL,   0,            1,                 1,      'de'),
(2,   'developer',        'NPL',      'DEV',   NULL,   0,            1,                 1,      'de');

--
-- user contracts
--
INSERT INTO `contracts` (`id`, `user_id`, `start`, `end`, `hours_0`, `hours_1`, `hours_2`, `hours_3`, `hours_4`, `hours_5`, `hours_6`) VALUES
(1,    1,   '2020-01-01',      NULL,      0,       8,     8,         8,         8,         8,         0);

--
-- teams
--
INSERT INTO `teams` (`id`, `name`, `lead_user_id`) VALUES
(1,    'Kuchenbäcker',     1),
(2,    'Hackerman',        1);

--
-- users-to-teams
--
INSERT INTO `teams_users` (`id`, `team_id`, `user_id`) VALUES
(1,    1,   1);

--
-- customers
--
INSERT INTO `customers` (`id`, `name`, `active`, `global`) VALUES
(1,    'Der Bäcker von nebenan',       1,        0);

--
-- customers-to-teams
--
INSERT INTO `teams_customers` (`id`, `team_id`, `customer_id`) VALUES
(1,    1,   1);

--
-- projects
--
INSERT INTO `projects` (`id`, `customer_id`, `name`, `jira_id`, `ticket_system`, `active`, `global`, `estimation`, `offer`, `billing`, `cost_center`, `internal_ref`, `external_ref`, `project_lead_id`, `technical_lead_id`, `invoice`, `additional_information_from_external`, `internal_jira_project_key`, `internal_jira_ticket_system`) VALUES
(1,    1,   'Server attack',  'SA',          NULL,   1,         0,               0,        '0',      0,            NULL,    NULL,      NULL,          1,              1,              NULL,              0,                   '',        0);

--
-- presets
--
INSERT INTO `presets` (`id`, `name`, `customer_id`, `project_id`, `activity_id`, `description`) VALUES
(1,    'Urlaub',      1,     1,      1,             'Urlaub');

--
-- activity entries for first user for today
--
INSERT INTO `entries` (`id`, `day`, `start`, `end`, `customer_id`, `project_id`, `account_id`, `activity_id`, `ticket`, `worklog_id`, `description`, `duration`, `user_id`, `class`, `synced_to_ticketsystem`, `internal_jira_ticket_original_key`) VALUES
(1,    '1000-01-30',  '08:00:00',   '08:50:00',     1,             1,            NULL,         1,             'testGetLastEntriesAction',   NULL,         '/interpretation/entries',      50,        1,       1,                        0,                                   ''),
(2,    '1000-01-30',  '10:00:00',   '12:50:00',     1,             1,            NULL,         1,             'testGetLastEntriesAction',   NULL,         '/interpretation/entries',      170,       1,       1,                        0,                                   ''),
(3,    '1000-01-29',  '13:00:00',   '13:14:00',     1,             1,            NULL,         1,             'testGroupByWorktimeAction',  NULL,         '/interpretation/entries',      14,        1,       1,                        0,                                   '');
