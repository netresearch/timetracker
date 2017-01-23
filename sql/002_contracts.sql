CREATE TABLE IF NOT EXISTS `contracts` (
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
