CREATE TABLE host_state (
  checksum VARBINARY(20) NOT NULL, -- sha1(hostname)
  host VARCHAR(255) NOT NULL,
  state TINYINT NOT NULL,
  hard_state TINYINT DEFAULT NULL,
  state_type ENUM('hard', 'soft') NOT NULL,
  attempt TINYINT NOT NULL,
  severity INT UNSIGNED NOT NULL,
  problem ENUM('y', 'n') NOT NULL,
  reachable ENUM('y', 'n') NOT NULL,
  acknowledged ENUM('y', 'n') NOT NULL,
  in_downtime ENUM('y', 'n') NOT NULL,
  last_update BIGINT NOT NULL,
  last_state_change BIGINT NOT NULL,
  last_comment_checksum VARBINARY(20) DEFAULT NULL,
  check_source_checksum VARBINARY(20) DEFAULT NULL,
  PRIMARY KEY (checksum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE service_state (
  checksum VARBINARY(20) NOT NULL, -- sha1(hostname)
  host VARCHAR(255) NOT NULL,
  service VARCHAR(255) NOT NULL,
  state TINYINT NOT NULL,
  hard_state TINYINT DEFAULT NULL,
  state_type ENUM('hard', 'soft') NOT NULL,
  attempt TINYINT NOT NULL,
  severity INT UNSIGNED NOT NULL,
  problem ENUM('y', 'n') NOT NULL,
  reachable ENUM('y', 'n') NOT NULL,
  acknowledged ENUM('y', 'n') NOT NULL,
  in_downtime ENUM('y', 'n') NOT NULL,
  last_update BIGINT NOT NULL,
  last_state_change BIGINT NOT NULL,
  last_comment_checksum VARBINARY(20) DEFAULT NULL,
  check_source_checksum VARBINARY(20) DEFAULT NULL,
  PRIMARY KEY (checksum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
