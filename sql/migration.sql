
UPDATE projects SET ticket_system='1' WHERE ticket_system LIKE 'NR-JIRA';
UPDATE projects SET ticket_system='2' WHERE ticket_system LIKE 'AIDA-JIRA';
UPDATE projects SET ticket_system='3' WHERE ticket_system LIKE 'OTRS';
UPDATE projects SET ticket_system=NULL WHERE ticket_system LIKE '';

ALTER TABLE  `projects` CHANGE  `ticket_system`  `ticket_system` INT NULL DEFAULT NULL;

ALTER TABLE `projects`
  ADD CONSTRAINT `projects_ibfk_2` FOREIGN KEY (`ticket_system`) REFERENCES `ticket_systems` (`id`);
