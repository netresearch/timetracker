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
