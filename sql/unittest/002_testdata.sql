SET
  FOREIGN_KEY_CHECKS = 0;

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

SET
  FOREIGN_KEY_CHECKS = 1;

--
-- activities
--
INSERT INTO
  `activities` (`id`, `name`, `needs_ticket`, `factor`)
VALUES
  (1, 'Entwicklung', 0, 1),
  (2, 'Tests', 0, 1),
  (3, 'Weinen', 0, 1);

--
-- users
--
INSERT INTO
  `users` (
    `id`,
    `username`,
    `abbr`,
    `type`,
    `jira_token`,
    `show_empty_line`,
    `suggest_time`,
    `show_future`,
    `locale`
  )
VALUES
  (1, 'unittest', 'UTE', 'PL', NULL, 0, 1, 1, 'de'),
  (2, 'developer', 'NPL', 'DEV', NULL, 0, 1, 1, 'de'),
  (3, 'i.myself', 'IMY', 'PL', NULL, 0, 1, 1, 'de'),
  (
    4,
    'testGroupByActionUser',
    'NPL',
    'DEV',
    NULL,
    0,
    1,
    1,
    'de'
  ),
  (5, 'noContract', 'NCO', 'PL', NULL, 0, 1, 1, 'de');

--
-- user contracts
--
INSERT INTO
  `contracts` (
    `id`,
    `user_id`,
    `start`,
    `end`,
    `hours_0`,
    `hours_1`,
    `hours_2`,
    `hours_3`,
    `hours_4`,
    `hours_5`,
    `hours_6`
  )
VALUES
  (
    1,
    1,
    '2020-01-01',
    '2020-01-31',
    0,
    1,
    2,
    3,
    4,
    5,
    0
  ),
  (
    2,
    1,
    '2020-02-01',
    NULL,
    0,
    1.1,
    2.2,
    3.3,
    4.4,
    5.5,
    0.5
  ),
  (
    3,
    3,
    '2020-01-01',
    '2021-01-01',
    1,
    1,
    1,
    1,
    1,
    1,
    1
  ),
  (4, 2, '2020-01-01', NULL, 1, 2, 3, 4, 5, 5, 5);

--
-- teams
--
INSERT INTO
  `teams` (`id`, `name`, `lead_user_id`)
VALUES
  (1, 'Kuchenbäcker', 1),
  (2, 'Hackerman', 2);

--
-- users-to-teams
--
INSERT INTO
  `teams_users` (`team_id`, `user_id`)
VALUES
  (1, 1),
  (2, 2);

--
-- customers
--
INSERT INTO
  `customers` (`id`, `name`, `active`, `global`)
VALUES
  (1, 'Der Bäcker von nebenan', 1, 0),
  (2, 'Der nebenan vom Bäcker', 0, 0),
  (3, 'Der Globale Customer', 1, 1);

--
-- customers-to-teams
--
INSERT INTO
  `teams_customers` (`team_id`, `customer_id`)
VALUES
  (1, 1),
  (2, 2);

--
-- projects
--
INSERT INTO
  `projects` (
    `id`,
    `customer_id`,
    `name`,
    `jira_id`,
    `ticket_system`,
    `active`,
    `global`,
    `estimation`,
    `offer`,
    `billing`,
    `cost_center`,
    `internal_ref`,
    `external_ref`,
    `project_lead_id`,
    `technical_lead_id`,
    `invoice`,
    `additional_information_from_external`,
    `internal_jira_project_key`,
    `internal_jira_ticket_system`
  )
VALUES
  (
    1,
    1,
    'Das Kuchenbacken',
    'SA',
    NULL,
    1,
    0,
    0,
    '0',
    0,
    NULL,
    NULL,
    NULL,
    1,
    1,
    NULL,
    0,
    '',
    0
  ),
  (
    2,
    1,
    'Attack Server',
    'TIM-1',
    NULL,
    0,
    0,
    0,
    '0',
    0,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    0,
    '',
    0
  ),
  (
    3,
    3,
    'GlobalProject',
    'TIM-1',
    NULL,
    0,
    0,
    0,
    '0',
    0,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    0,
    '',
    0
  );

--
-- presets
--
INSERT INTO
  `presets` (
    `id`,
    `name`,
    `customer_id`,
    `project_id`,
    `activity_id`,
    `description`
  )
VALUES
  (1, 'Urlaub', 1, 1, 1, 'Urlaub');

--
-- holidays for 2020 (using day as primary key, no id column)
--
INSERT INTO
  `holidays` (`day`, `name`)
VALUES
  ('2020-01-01', 'Neujahr');

--
-- activity entries for first user for today
--
INSERT INTO
  `entries` (
    `id`,
    `day`,
    `start`,
    `end`,
    `customer_id`,
    `project_id`,
    `account_id`,
    `activity_id`,
    `ticket`,
    `worklog_id`,
    `description`,
    `duration`,
    `user_id`,
    `class`,
    `synced_to_ticketsystem`,
    `internal_jira_ticket_original_key`
  )
VALUES
  (
    1,
    '1000-01-30',
    '08:00:00',
    '08:50:00',
    1,
    1,
    NULL,
    1,
    'testGetLastEntriesAction',
    NULL,
    '/interpretation/entries',
    50,
    1,
    1,
    0,
    ''
  ),
  (
    2,
    '1000-01-30',
    '10:00:00',
    '12:50:00',
    1,
    1,
    NULL,
    1,
    'testGetLastEntriesAction',
    NULL,
    '/interpretation/entries',
    170,
    1,
    1,
    0,
    ''
  ),
  (
    3,
    '1000-01-29',
    '13:00:00',
    '13:14:00',
    1,
    1,
    NULL,
    1,
    'testGroupByWorktimeAction',
    NULL,
    '/interpretation/entries',
    14,
    1,
    1,
    0,
    ''
  ),
  (
    4,
    '2023-10-24',
    '13:00:00',
    '13:25:00',
    1,
    1,
    NULL,
    1,
    'testGetDataAction',
    NULL,
    'testGetDataAction',
    25,
    1,
    1,
    0,
    ''
  ),
  (
    5,
    '2023-10-20',
    '14:00:00',
    '14:25:00',
    1,
    1,
    NULL,
    1,
    'testGetDataAction',
    NULL,
    'testGetDataAction',
    25,
    1,
    1,
    0,
    ''
  ),
  (
    6,
    '500-01-30',
    '14:00:00',
    '14:50:00',
    1,
    1,
    NULL,
    1,
    'testGroupByActivityAction',
    NULL,
    'testGroupByActivityAction',
    50,
    3,
    1,
    0,
    ''
  ),
  (
    7,
    '500-01-31',
    '14:00:00',
    '14:20:00',
    1,
    1,
    NULL,
    1,
    'testGroupByActivityAction',
    NULL,
    'testGroupByActivityAction',
    20,
    3,
    1,
    0,
    ''
  ),
  -- Entry for user ID 2 (developer) in February 2020 for tests
  (
    8,
    '2020-02-08',
    '09:00:00',
    '14:30:00',
    1,
    1,
    NULL,
    1,
    'testGetDataActionForParameter',
    NULL,
    'Test entry for developer',
    330,
    2,
    1,
    0,
    ''
  );

--
-- ticket_systems entries for first user for today
--
INSERT INTO
  `ticket_systems` (
    `id`,
    `name`,
    `type`,
    `book_time`,
    `url`,
    `login`,
    `password`,
    `public_key`,
    `private_key`,
    `ticketurl`,
    `oauth_consumer_key`,
    `oauth_consumer_secret`
  )
VALUES
  (
    1,
    'testSystem',
    '',
    0,
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    ''
  );