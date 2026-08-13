-- MyÚčto.cz — MZ-22-W1a: verzované zdroje specifikace JMHZ a číselníky.
-- Globální národní specifikace, proto záměrně bez supplier_id.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_jmhz_spec_packages (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  package_key           VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  schema_version        VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  xsd_version           VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  dictionary_version    VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  control_catalog_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  process_version       VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  instructions_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  manifest_json         LONGTEXT NOT NULL,
  manifest_sha256       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_jmhz_spec_package_key (package_key),
  CONSTRAINT chk_payroll_jmhz_spec_manifest_json CHECK (JSON_VALID(manifest_json)),
  CONSTRAINT chk_payroll_jmhz_spec_manifest_hash
    CHECK (manifest_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_jmhz_dictionary_attributes (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  package_id      BIGINT UNSIGNED NOT NULL,
  attribute_id    VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name            VARCHAR(500) NOT NULL,
  area            VARCHAR(255) NULL,
  class_name      VARCHAR(255) NULL,
  subclass_name   VARCHAR(255) NULL,
  data_type       VARCHAR(255) NULL,
  data_type_refinement VARCHAR(255) NULL,
  cardinality     VARCHAR(64) NULL,
  regzec_xsd_mapping VARCHAR(1000) NULL,
  xsd_mapping     VARCHAR(1000) NULL,
  codebook_key    VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
  employer_registration_marker VARCHAR(255) NULL,
  employee_registration_marker VARCHAR(255) NULL,
  monthly_marker  VARCHAR(64) NULL,
  row_hash        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_jmhz_attribute (package_id, attribute_id),
  KEY idx_payroll_jmhz_attribute_codebook (package_id, codebook_key),
  CONSTRAINT fk_payroll_jmhz_attribute_package
    FOREIGN KEY (package_id) REFERENCES payroll_jmhz_spec_packages(id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_jmhz_attribute_hash
    CHECK (row_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_jmhz_codebooks (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  package_id      BIGINT UNSIGNED NOT NULL,
  codebook_key    VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_kind     VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_name     VARCHAR(500) NOT NULL,
  source_url      VARCHAR(1000) NULL,
  source_metadata_json LONGTEXT NOT NULL,
  entry_count     INT UNSIGNED NOT NULL,
  content_hash    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_jmhz_codebook (package_id, codebook_key),
  UNIQUE KEY uq_payroll_jmhz_codebook_package (id, package_id),
  CONSTRAINT fk_payroll_jmhz_codebook_package
    FOREIGN KEY (package_id) REFERENCES payroll_jmhz_spec_packages(id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_jmhz_codebook_source
    CHECK (source_kind IN ('embedded', 'external_reference')),
  CONSTRAINT chk_payroll_jmhz_codebook_metadata
    CHECK (JSON_VALID(source_metadata_json)),
  CONSTRAINT chk_payroll_jmhz_codebook_hash
    CHECK (content_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_jmhz_codebook_count
    CHECK ((source_kind = 'embedded' AND entry_count > 0)
        OR (source_kind = 'external_reference' AND entry_count = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_jmhz_dictionary_attributes
  ADD FOREIGN KEY IF NOT EXISTS fk_payroll_jmhz_attribute_codebook
    (package_id, codebook_key)
    REFERENCES payroll_jmhz_codebooks(package_id, codebook_key)
    ON UPDATE RESTRICT ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS payroll_jmhz_codebook_entries (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  package_id      BIGINT UNSIGNED NOT NULL,
  codebook_id     BIGINT UNSIGNED NOT NULL,
  item_code       VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  label           TEXT NOT NULL,
  parent_code     VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  ordinal         INT UNSIGNED NOT NULL,
  metadata_json   LONGTEXT NOT NULL,
  row_hash        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_jmhz_codebook_entry (package_id, codebook_id, item_code),
  CONSTRAINT fk_payroll_jmhz_codebook_entry_package
    FOREIGN KEY (package_id) REFERENCES payroll_jmhz_spec_packages(id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_codebook_entry_codebook
    FOREIGN KEY (codebook_id, package_id)
    REFERENCES payroll_jmhz_codebooks(id, package_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_jmhz_codebook_entry_metadata CHECK (JSON_VALID(metadata_json)),
  CONSTRAINT chk_payroll_jmhz_codebook_entry_hash
    CHECK (row_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_jmhz_spec_package_update
BEFORE UPDATE ON payroll_jmhz_spec_packages
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'JMHZ specification packages are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_jmhz_spec_package_delete
BEFORE DELETE ON payroll_jmhz_spec_packages
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'JMHZ specification packages are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_jmhz_dictionary_attribute_update
BEFORE UPDATE ON payroll_jmhz_dictionary_attributes
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'JMHZ dictionary attributes are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_jmhz_dictionary_attribute_insert
BEFORE INSERT ON payroll_jmhz_dictionary_attributes
FOR EACH ROW
BEGIN
  IF (SELECT COUNT(*) FROM payroll_jmhz_dictionary_attributes
       WHERE package_id = NEW.package_id) >= COALESCE((
         SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(manifest_json, '$.payload.counts.attributes')) AS UNSIGNED)
           FROM payroll_jmhz_spec_packages WHERE id = NEW.package_id
       ), 0) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'JMHZ dictionary package already contains all declared attributes';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_jmhz_dictionary_attribute_delete
BEFORE DELETE ON payroll_jmhz_dictionary_attributes
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'JMHZ dictionary attributes are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_jmhz_codebook_update
BEFORE UPDATE ON payroll_jmhz_codebooks
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'JMHZ codebooks are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_jmhz_codebook_insert
BEFORE INSERT ON payroll_jmhz_codebooks
FOR EACH ROW
BEGIN
  IF (SELECT COUNT(*) FROM payroll_jmhz_codebooks
       WHERE package_id = NEW.package_id) >= COALESCE((
         SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(manifest_json, '$.payload.counts.codebooks')) AS UNSIGNED)
           FROM payroll_jmhz_spec_packages WHERE id = NEW.package_id
       ), 0) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'JMHZ package already contains all declared codebooks';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_jmhz_codebook_delete
BEFORE DELETE ON payroll_jmhz_codebooks
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'JMHZ codebooks are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_jmhz_codebook_entry_update
BEFORE UPDATE ON payroll_jmhz_codebook_entries
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'JMHZ codebook entries are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_jmhz_codebook_entry_insert
BEFORE INSERT ON payroll_jmhz_codebook_entries
FOR EACH ROW
BEGIN
  IF (SELECT COUNT(*) FROM payroll_jmhz_codebook_entries
       WHERE package_id = NEW.package_id) >= COALESCE((
         SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(manifest_json, '$.payload.counts.codebook_entries')) AS UNSIGNED)
           FROM payroll_jmhz_spec_packages WHERE id = NEW.package_id
       ), 0) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'JMHZ package already contains all declared codebook entries';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_jmhz_codebook_entry_delete
BEFORE DELETE ON payroll_jmhz_codebook_entries
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'JMHZ codebook entries are append-only';
END//

DELIMITER ;
