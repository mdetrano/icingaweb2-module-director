DROP TABLE IF EXISTS ddo_host;
CREATE TABLE ddo_host (
  checksum VARBINARY(20) NOT NULL COMMENT 'sha1(name)',

  name VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  name_ci VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  label VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,

  action_url VARCHAR(2083),
  notes_url VARCHAR(2083),

  address VARCHAR(255),
  address6 VARCHAR(255),
  address_bin BINARY(4),
  address6_bin BINARY(16),

  active_checks_enabled ENUM('y', 'n'),
  event_handler_enabled ENUM('y', 'n'),
  flapping_enabled ENUM('y', 'n'),
  notifications_enabled ENUM('y', 'n'),
  passive_checks_enabled ENUM('y', 'n'),
  perfdata_enabled ENUM('y', 'n'),

  check_command VARCHAR(255),
  check_interval INT(10) UNSIGNED,
  check_retry_interval INT(10) UNSIGNED,

--  ctime BIGINT NOT NULL,
--  mtime BIGINT NOT NULL,

  PRIMARY KEY (checksum),
  UNIQUE KEY idx_host_name (name),
  INDEX idx_host_name_ci (name_ci)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS ddo_host_group;
CREATE TABLE ddo_host_group (
  checksum VARBINARY(20) NOT NULL COMMENT 'sha1(name)',

  name VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  name_ci VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  label VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,

--  ctime BIGINT NOT NULL,
--  mtime BIGINT NOT NULL,

  PRIMARY KEY (checksum),
  UNIQUE KEY idx_host_group_name (name),
  INDEX idx_host_group_name_ci (name_ci)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS ddo_host_group_member;
CREATE TABLE ddo_host_group_member (
  host_group_checksum VARBINARY(20) NOT NULL,
  host_checksum VARBINARY(20) NOT NULL,

--  ctime BIGINT NOT NULL,
--  mtime BIGINT NOT NULL,

  PRIMARY KEY (host_group_checksum, host_checksum),

  CONSTRAINT fk_host_group
  FOREIGN KEY (host_group_checksum)
  REFERENCES ddo_host_group (checksum)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_host
  FOREIGN KEY (host_checksum)
  REFERENCES ddo_host (checksum)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
