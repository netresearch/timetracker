
CREATE DATABASE IF NOT EXISTS `unittest`;

CREATE USER IF NOT EXISTS 'unittest'@'%' IDENTIFIED BY 'unittest';

GRANT ALL PRIVILEGES ON *.* TO 'unittest'@'%';
