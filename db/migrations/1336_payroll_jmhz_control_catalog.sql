-- MyÚčto.cz — MZ-22-W1b: immutable zdrojový katalog kontrol JMHZ.
-- Texty a Excel vzorce jsou pouze auditní data; tato migrace nevytváří executable validátory.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_jmhz_control_catalogs (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  package_id      BIGINT UNSIGNED NOT NULL,
  catalog_key     VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  version         VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_filename VARCHAR(255) NOT NULL,
  source_sha256   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  manifest_json   LONGTEXT NOT NULL,
  manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  control_count   INT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_jmhz_control_catalog (package_id, catalog_key),
  UNIQUE KEY uq_jmhz_control_catalog_package (id, package_id),
  CONSTRAINT fk_jmhz_control_catalog_package FOREIGN KEY (package_id)
    REFERENCES payroll_jmhz_spec_packages(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_jmhz_control_catalog_manifest CHECK (JSON_VALID(manifest_json)),
  CONSTRAINT chk_jmhz_control_catalog_hashes CHECK (
    source_sha256 REGEXP '^[0-9a-f]{64}$'
    AND manifest_sha256 REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_jmhz_control_definitions (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  catalog_id            BIGINT UNSIGNED NOT NULL,
  package_id            BIGINT UNSIGNED NOT NULL,
  control_id            INT UNSIGNED NOT NULL,
  source_row            INT UNSIGNED NOT NULL,
  name                  TEXT NOT NULL,
  attribute_refs_raw    TEXT NULL,
  symbolic_refs_json    LONGTEXT NOT NULL,
  area                  VARCHAR(500) NULL,
  rejection_scope       VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  owner_name            VARCHAR(255) NOT NULL,
  portal_system         VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  portal_passability    VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  remote_system         VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  remote_passability    VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  category              VARCHAR(32) NULL,
  detail_text           LONGTEXT NOT NULL,
  detail_formula        LONGTEXT NULL,
  error_message         LONGTEXT NOT NULL,
  error_message_formula LONGTEXT NULL,
  source_label          VARCHAR(500) NOT NULL,
  note                  TEXT NULL,
  row_hash              CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_jmhz_control_definition (catalog_id, control_id),
  UNIQUE KEY uq_jmhz_control_definition_package (id, catalog_id, package_id, control_id),
  CONSTRAINT fk_jmhz_control_definition_catalog FOREIGN KEY (catalog_id, package_id)
    REFERENCES payroll_jmhz_control_catalogs(id, package_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_jmhz_control_definition_symbolic CHECK (JSON_VALID(symbolic_refs_json)),
  CONSTRAINT chk_jmhz_control_definition_scope CHECK (
    rejection_scope IN ('pvpoj','employee_form','global','unassigned','summary','unavailable')
  ),
  CONSTRAINT chk_jmhz_control_definition_systems CHECK (
    portal_system IN ('eportal','dis','cjmhz','unavailable')
    AND remote_system IN ('eportal','dis','cjmhz','unavailable')
  ),
  CONSTRAINT chk_jmhz_control_definition_passability CHECK (
    portal_passability IN ('blocking','passable','unavailable')
    AND remote_passability IN ('blocking','passable','unavailable')
  ),
  CONSTRAINT chk_jmhz_control_definition_hash CHECK (row_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_jmhz_control_attribute_refs (
  catalog_id    BIGINT UNSIGNED NOT NULL,
  package_id    BIGINT UNSIGNED NOT NULL,
  definition_id BIGINT UNSIGNED NOT NULL,
  control_id    INT UNSIGNED NOT NULL,
  attribute_id  VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ordinal       INT UNSIGNED NOT NULL,
  row_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (definition_id, ordinal),
  UNIQUE KEY uq_jmhz_control_attribute (definition_id, attribute_id),
  CONSTRAINT fk_jmhz_control_attribute_definition
    FOREIGN KEY (definition_id, catalog_id, package_id, control_id)
    REFERENCES payroll_jmhz_control_definitions(id, catalog_id, package_id, control_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_jmhz_control_attribute_dictionary
    FOREIGN KEY (package_id, attribute_id)
    REFERENCES payroll_jmhz_dictionary_attributes(package_id, attribute_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_jmhz_control_attribute_hash CHECK (row_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_jmhz_control_parameters (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  catalog_id    BIGINT UNSIGNED NOT NULL,
  package_id    BIGINT UNSIGNED NOT NULL,
  parameter_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_row    INT UNSIGNED NOT NULL,
  name          TEXT NOT NULL,
  control_refs_raw VARCHAR(255) NOT NULL,
  control_refs_formatted VARCHAR(255) NOT NULL,
  control_refs_anomaly VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
  row_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_jmhz_control_parameter (catalog_id, parameter_key),
  UNIQUE KEY uq_jmhz_control_parameter_row (catalog_id, source_row),
  UNIQUE KEY uq_jmhz_control_parameter_package (id, catalog_id, package_id),
  CONSTRAINT fk_jmhz_control_parameter_catalog FOREIGN KEY (catalog_id, package_id)
    REFERENCES payroll_jmhz_control_catalogs(id, package_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_jmhz_control_parameter_hash CHECK (row_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_jmhz_control_parameter_refs (
  catalog_id    BIGINT UNSIGNED NOT NULL,
  package_id    BIGINT UNSIGNED NOT NULL,
  parameter_id  BIGINT UNSIGNED NOT NULL,
  control_id    INT UNSIGNED NOT NULL,
  definition_id BIGINT UNSIGNED NULL,
  ordinal       INT UNSIGNED NOT NULL,
  resolution    VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  row_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (parameter_id, ordinal),
  UNIQUE KEY uq_jmhz_parameter_ref_control (parameter_id, control_id),
  CONSTRAINT fk_jmhz_parameter_ref_parameter
    FOREIGN KEY (parameter_id, catalog_id, package_id)
    REFERENCES payroll_jmhz_control_parameters(id, catalog_id, package_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_jmhz_parameter_ref_definition
    FOREIGN KEY (definition_id, catalog_id, package_id, control_id)
    REFERENCES payroll_jmhz_control_definitions(id, catalog_id, package_id, control_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_jmhz_parameter_ref_resolution CHECK (
    (resolution = 'present' AND definition_id IS NOT NULL)
    OR (resolution = 'missing' AND definition_id IS NULL)
  ),
  CONSTRAINT chk_jmhz_parameter_ref_hash CHECK (row_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_jmhz_control_parameter_values (
  catalog_id     BIGINT UNSIGNED NOT NULL,
  package_id     BIGINT UNSIGNED NOT NULL,
  parameter_id   BIGINT UNSIGNED NOT NULL,
  source_cell    VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  effective_from DATE NOT NULL,
  raw_type       VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  raw_value      VARCHAR(255) NOT NULL,
  normalized_value VARCHAR(255) NOT NULL,
  canonical_value VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  row_hash       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (parameter_id, effective_from),
  CONSTRAINT fk_jmhz_parameter_value_parameter
    FOREIGN KEY (parameter_id, catalog_id, package_id)
    REFERENCES payroll_jmhz_control_parameters(id, catalog_id, package_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_jmhz_parameter_value_decimal CHECK (
    canonical_value REGEXP '^-?[0-9]+(\\.[0-9]+)?$'
  ),
  CONSTRAINT chk_jmhz_parameter_value_type CHECK (raw_type IN ('n','s')),
  CONSTRAINT chk_jmhz_parameter_value_hash CHECK (row_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_catalog_bu BEFORE UPDATE ON payroll_jmhz_control_catalogs
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control catalogs are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_catalog_bd BEFORE DELETE ON payroll_jmhz_control_catalogs
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control catalogs are append-only'; END//

CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_definition_bi BEFORE INSERT ON payroll_jmhz_control_definitions
FOR EACH ROW BEGIN
  IF (SELECT COUNT(*) FROM payroll_jmhz_control_definitions WHERE catalog_id = NEW.catalog_id)
      >= COALESCE((SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(manifest_json, '$.payload.counts.controls')) AS UNSIGNED)
          FROM payroll_jmhz_control_catalogs WHERE id = NEW.catalog_id), 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control catalog already contains all definitions';
  END IF;
END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_definition_bu BEFORE UPDATE ON payroll_jmhz_control_definitions
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control definitions are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_definition_bd BEFORE DELETE ON payroll_jmhz_control_definitions
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control definitions are append-only'; END//

CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_attribute_bi BEFORE INSERT ON payroll_jmhz_control_attribute_refs
FOR EACH ROW BEGIN
  IF (SELECT COUNT(*) FROM payroll_jmhz_control_attribute_refs WHERE catalog_id = NEW.catalog_id)
      >= COALESCE((SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(manifest_json, '$.payload.counts.attribute_refs')) AS UNSIGNED)
          FROM payroll_jmhz_control_catalogs WHERE id = NEW.catalog_id), 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control catalog already contains all attribute refs';
  END IF;
END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_attribute_bu BEFORE UPDATE ON payroll_jmhz_control_attribute_refs
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control attribute refs are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_attribute_bd BEFORE DELETE ON payroll_jmhz_control_attribute_refs
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control attribute refs are append-only'; END//

CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_parameter_bi BEFORE INSERT ON payroll_jmhz_control_parameters
FOR EACH ROW BEGIN
  IF (SELECT COUNT(*) FROM payroll_jmhz_control_parameters WHERE catalog_id = NEW.catalog_id)
      >= COALESCE((SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(manifest_json, '$.payload.counts.parameters')) AS UNSIGNED)
          FROM payroll_jmhz_control_catalogs WHERE id = NEW.catalog_id), 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control catalog already contains all parameters';
  END IF;
END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_parameter_bu BEFORE UPDATE ON payroll_jmhz_control_parameters
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control parameters are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_parameter_bd BEFORE DELETE ON payroll_jmhz_control_parameters
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control parameters are append-only'; END//

CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_param_ref_bi BEFORE INSERT ON payroll_jmhz_control_parameter_refs
FOR EACH ROW BEGIN
  IF (SELECT COUNT(*) FROM payroll_jmhz_control_parameter_refs WHERE catalog_id = NEW.catalog_id)
      >= COALESCE((SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(manifest_json, '$.payload.counts.parameter_control_refs')) AS UNSIGNED)
          FROM payroll_jmhz_control_catalogs WHERE id = NEW.catalog_id), 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control catalog already contains all parameter refs';
  END IF;
END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_param_ref_bu BEFORE UPDATE ON payroll_jmhz_control_parameter_refs
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control parameter refs are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_param_ref_bd BEFORE DELETE ON payroll_jmhz_control_parameter_refs
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control parameter refs are append-only'; END//

CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_param_value_bi BEFORE INSERT ON payroll_jmhz_control_parameter_values
FOR EACH ROW BEGIN
  IF (SELECT COUNT(*) FROM payroll_jmhz_control_parameter_values WHERE catalog_id = NEW.catalog_id)
      >= COALESCE((SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(manifest_json, '$.payload.counts.parameter_values')) AS UNSIGNED)
          FROM payroll_jmhz_control_catalogs WHERE id = NEW.catalog_id), 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control catalog already contains all parameter values';
  END IF;
END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_param_value_bu BEFORE UPDATE ON payroll_jmhz_control_parameter_values
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control parameter values are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_ctl_param_value_bd BEFORE DELETE ON payroll_jmhz_control_parameter_values
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ control parameter values are append-only'; END//

DELIMITER ;
