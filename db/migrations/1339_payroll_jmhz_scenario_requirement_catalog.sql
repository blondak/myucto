-- MyÚčto.cz — MZ-22-W1c: immutable zdrojový katalog scénářů a povinností JMHZ.
-- Volné podmínky a odvozené Excel matice jsou pouze auditní data, ne spustitelná pravidla.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_jmhz_scenario_catalogs (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  package_id         BIGINT UNSIGNED NOT NULL,
  catalog_key        VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  version            VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_filename    VARCHAR(255) NOT NULL,
  source_sha256      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  manifest_json      LONGTEXT NOT NULL,
  manifest_sha256    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  scenario_count     INT UNSIGNED NOT NULL,
  interaction_count  INT UNSIGNED NOT NULL,
  matrix_count       INT UNSIGNED NOT NULL,
  requirement_count  INT UNSIGNED NOT NULL,
  interaction_attribute_ref_count INT UNSIGNED NOT NULL,
  attribute_axis_count INT UNSIGNED NOT NULL,
  evidence_axis_count INT UNSIGNED NOT NULL,
  evidence_member_count INT UNSIGNED NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_jmhz_scenario_catalog (package_id, catalog_key),
  UNIQUE KEY uq_jmhz_scenario_catalog_package (id, package_id),
  CONSTRAINT fk_jmhz_scenario_catalog_package FOREIGN KEY (package_id)
    REFERENCES payroll_jmhz_spec_packages(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_jmhz_scenario_catalog_manifest CHECK (JSON_VALID(manifest_json)),
  CONSTRAINT chk_jmhz_scenario_catalog_hashes CHECK (
    source_sha256 REGEXP '^[0-9a-f]{64}$'
    AND manifest_sha256 REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_jmhz_requirement_matrices (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  catalog_id        BIGINT UNSIGNED NOT NULL,
  package_id        BIGINT UNSIGNED NOT NULL,
  matrix_key        VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  matrix_kind       VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_sheet      VARCHAR(128) NOT NULL,
  source_header_row INT UNSIGNED NOT NULL,
  selector_raw      TEXT NULL,
  row_count         INT UNSIGNED NOT NULL,
  matrix_hash       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  row_hash          CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_jmhz_requirement_matrix (catalog_id, matrix_key),
  UNIQUE KEY uq_jmhz_requirement_matrix_package (id, catalog_id, package_id),
  CONSTRAINT fk_jmhz_requirement_matrix_catalog FOREIGN KEY (catalog_id, package_id)
    REFERENCES payroll_jmhz_scenario_catalogs(id, package_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_jmhz_requirement_matrix_kind CHECK (
    matrix_kind IN ('part','scenario','foundation','interaction')
  ),
  CONSTRAINT chk_jmhz_requirement_matrix_hashes CHECK (
    matrix_hash REGEXP '^[0-9a-f]{64}$' AND row_hash REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_jmhz_scenario_definitions (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  catalog_id               BIGINT UNSIGNED NOT NULL,
  package_id               BIGINT UNSIGNED NOT NULL,
  scenario_key             VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_sheet             VARCHAR(128) NOT NULL,
  source_row               INT UNSIGNED NOT NULL,
  ordinal                  INT UNSIGNED NOT NULL,
  matrix_id                BIGINT UNSIGNED NOT NULL,
  selector_raw_type        VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  selector_raw             TEXT NOT NULL,
  name_raw                 TEXT NOT NULL,
  condition_raw            TEXT NOT NULL,
  business_description_raw LONGTEXT NOT NULL,
  business_description_cell_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  xsd_entrypoint           VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  selection_kind           VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  row_hash                 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_jmhz_scenario_definition (catalog_id, scenario_key),
  UNIQUE KEY uq_jmhz_scenario_ordinal (catalog_id, ordinal),
  UNIQUE KEY uq_jmhz_scenario_matrix (matrix_id),
  UNIQUE KEY uq_jmhz_scenario_definition_package (id, catalog_id, package_id),
  CONSTRAINT fk_jmhz_scenario_definition_catalog FOREIGN KEY (catalog_id, package_id)
    REFERENCES payroll_jmhz_scenario_catalogs(id, package_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_jmhz_scenario_definition_matrix
    FOREIGN KEY (matrix_id, catalog_id, package_id)
    REFERENCES payroll_jmhz_requirement_matrices(id, catalog_id, package_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_jmhz_scenario_selection_kind CHECK (
    selection_kind IN ('activity_raw','manual_raw')
  ),
  CONSTRAINT chk_jmhz_scenario_selector_type CHECK (selector_raw_type IN ('n','s')),
  CONSTRAINT chk_jmhz_scenario_description_kind CHECK (
    business_description_cell_kind IN ('plain','rich_text')
  ),
  CONSTRAINT chk_jmhz_scenario_definition_hash CHECK (row_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_jmhz_interaction_definitions (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  catalog_id       BIGINT UNSIGNED NOT NULL,
  package_id       BIGINT UNSIGNED NOT NULL,
  interaction_key  VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  interaction_id_raw VARCHAR(64) NOT NULL,
  source_sheet     VARCHAR(128) NOT NULL,
  source_row       INT UNSIGNED NOT NULL,
  ordinal          INT UNSIGNED NOT NULL,
  matrix_id        BIGINT UNSIGNED NULL,
  condition_raw    TEXT NOT NULL,
  portal_text      TEXT NULL,
  note_raw         TEXT NULL,
  trigger_kind     VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  row_hash         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_jmhz_interaction_definition (catalog_id, interaction_key),
  UNIQUE KEY uq_jmhz_interaction_ordinal (catalog_id, ordinal),
  UNIQUE KEY uq_jmhz_interaction_matrix (matrix_id),
  UNIQUE KEY uq_jmhz_interaction_definition_package (id, catalog_id, package_id),
  CONSTRAINT fk_jmhz_interaction_definition_catalog FOREIGN KEY (catalog_id, package_id)
    REFERENCES payroll_jmhz_scenario_catalogs(id, package_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_jmhz_interaction_definition_matrix
    FOREIGN KEY (matrix_id, catalog_id, package_id)
    REFERENCES payroll_jmhz_requirement_matrices(id, catalog_id, package_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_jmhz_interaction_trigger_kind CHECK (
    trigger_kind IN ('attribute_raw','virtual_raw','compound_raw','month_raw')
  ),
  CONSTRAINT chk_jmhz_interaction_definition_hash CHECK (row_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_jmhz_field_requirements (
  catalog_id        BIGINT UNSIGNED NOT NULL,
  package_id        BIGINT UNSIGNED NOT NULL,
  matrix_id         BIGINT UNSIGNED NOT NULL,
  attribute_id      VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_row        INT UNSIGNED NOT NULL,
  source_cell       VARCHAR(128) NOT NULL,
  requirement_kind  VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  requirement_raw   VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  condition_note_raw TEXT NULL,
  translation_raw   TEXT NULL,
  effect_kind       VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  effect_raw        VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL,
  row_hash          CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (matrix_id, attribute_id),
  UNIQUE KEY uq_jmhz_field_requirement_source (matrix_id, source_row),
  CONSTRAINT fk_jmhz_field_requirement_matrix
    FOREIGN KEY (matrix_id, catalog_id, package_id)
    REFERENCES payroll_jmhz_requirement_matrices(id, catalog_id, package_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_jmhz_field_requirement_attribute
    FOREIGN KEY (package_id, attribute_id)
    REFERENCES payroll_jmhz_dictionary_attributes(package_id, attribute_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_jmhz_field_requirement_kind CHECK (
    (requirement_kind = 'required' AND requirement_raw = 'P' AND condition_note_raw IS NULL)
    OR (requirement_kind = 'optional' AND requirement_raw = 'N' AND condition_note_raw IS NULL)
    OR (requirement_kind = 'conditional' AND requirement_raw = 'NSP'
        AND condition_note_raw IS NOT NULL AND condition_note_raw <> '')
  ),
  CONSTRAINT chk_jmhz_field_effect CHECK (
    (effect_kind = 'none' AND effect_raw IS NULL)
    OR (effect_kind = 'add' AND effect_raw = '+')
    OR (effect_kind = 'remove' AND effect_raw = '-')
  ),
  CONSTRAINT chk_jmhz_field_requirement_hash CHECK (row_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_jmhz_scenario_definitions
  ADD CONSTRAINT fk_jmhz_scenario_definition_matrix
    FOREIGN KEY IF NOT EXISTS (matrix_id, catalog_id, package_id)
    REFERENCES payroll_jmhz_requirement_matrices(id, catalog_id, package_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE payroll_jmhz_interaction_definitions
  ADD CONSTRAINT fk_jmhz_interaction_definition_matrix
    FOREIGN KEY IF NOT EXISTS (matrix_id, catalog_id, package_id)
    REFERENCES payroll_jmhz_requirement_matrices(id, catalog_id, package_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS payroll_jmhz_interaction_attribute_refs (
  catalog_id    BIGINT UNSIGNED NOT NULL,
  package_id    BIGINT UNSIGNED NOT NULL,
  interaction_id BIGINT UNSIGNED NOT NULL,
  attribute_id  VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ordinal       INT UNSIGNED NOT NULL,
  source_cell   VARCHAR(32) NOT NULL,
  source_match_raw VARCHAR(255) NOT NULL,
  row_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (interaction_id, ordinal),
  UNIQUE KEY uq_jmhz_interaction_attribute_ref (interaction_id, attribute_id),
  CONSTRAINT fk_jmhz_interaction_attribute_definition
    FOREIGN KEY (interaction_id, catalog_id, package_id)
    REFERENCES payroll_jmhz_interaction_definitions(id, catalog_id, package_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_jmhz_interaction_attribute_dictionary
    FOREIGN KEY (package_id, attribute_id)
    REFERENCES payroll_jmhz_dictionary_attributes(package_id, attribute_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_jmhz_interaction_attribute_hash CHECK (row_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_jmhz_master_attribute_axis (
  catalog_id   BIGINT UNSIGNED NOT NULL,
  package_id   BIGINT UNSIGNED NOT NULL,
  attribute_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ordinal      INT UNSIGNED NOT NULL,
  source_row   INT UNSIGNED NOT NULL,
  row_hash     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (catalog_id, ordinal),
  UNIQUE KEY uq_jmhz_master_axis_attribute (catalog_id, attribute_id),
  CONSTRAINT fk_jmhz_master_axis_catalog FOREIGN KEY (catalog_id, package_id)
    REFERENCES payroll_jmhz_scenario_catalogs(id, package_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_jmhz_master_axis_attribute FOREIGN KEY (package_id, attribute_id)
    REFERENCES payroll_jmhz_dictionary_attributes(package_id, attribute_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_jmhz_master_axis_hash CHECK (row_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_jmhz_matrix_evidence_axes (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  catalog_id          BIGINT UNSIGNED NOT NULL,
  package_id          BIGINT UNSIGNED NOT NULL,
  axis_key            VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  axis_kind           VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_column       VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_sheet        VARCHAR(128) NOT NULL,
  label_raw           TEXT NOT NULL,
  expected_matrix_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
  expected_effect     VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
  dimension_count     INT UNSIGNED NOT NULL,
  explicit_cell_count INT UNSIGNED NOT NULL,
  nonempty_count      INT UNSIGNED NOT NULL,
  blank_count         INT UNSIGNED NOT NULL,
  zero_count          INT UNSIGNED NOT NULL,
  one_count           INT UNSIGNED NOT NULL,
  raw_vector_sha256   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  dictionary_formula_count INT UNSIGNED NOT NULL,
  dictionary_formula_vector_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  dictionary_cached_vector_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  master_match_count  INT UNSIGNED NOT NULL,
  master_mismatch_count INT UNSIGNED NOT NULL,
  reconciliation_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  row_hash            CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_jmhz_matrix_evidence_axis (catalog_id, axis_key),
  UNIQUE KEY uq_jmhz_matrix_evidence_axis_package (id, catalog_id, package_id),
  CONSTRAINT fk_jmhz_matrix_evidence_axis_catalog FOREIGN KEY (catalog_id, package_id)
    REFERENCES payroll_jmhz_scenario_catalogs(id, package_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_jmhz_matrix_evidence_axis_kind CHECK (
    axis_kind IN ('reconciliation','derived_binary')
  ),
  CONSTRAINT chk_jmhz_matrix_evidence_effect CHECK (
    expected_effect IS NULL OR expected_effect IN ('add','remove','none')
  ),
  CONSTRAINT chk_jmhz_matrix_evidence_counts CHECK (
    explicit_cell_count + blank_count = dimension_count
    AND nonempty_count <= explicit_cell_count
    AND zero_count + one_count <= explicit_cell_count
  ),
  CONSTRAINT chk_jmhz_matrix_evidence_source_fidelity CHECK (
    (axis_kind = 'reconciliation' AND source_sheet = 'SLOVNÍK'
      AND dictionary_formula_vector_sha256 IS NOT NULL
      AND dictionary_cached_vector_sha256 IS NOT NULL
      AND master_match_count + master_mismatch_count = dimension_count)
    OR (axis_kind = 'derived_binary' AND source_sheet = 'MASTER'
      AND dictionary_formula_count = 0
      AND dictionary_formula_vector_sha256 IS NULL
      AND dictionary_cached_vector_sha256 IS NULL
      AND master_match_count = 0 AND master_mismatch_count = 0)
  ),
  CONSTRAINT chk_jmhz_matrix_evidence_status CHECK (
    reconciliation_status IN ('match','known_anomaly','not_applicable')
  ),
  CONSTRAINT chk_jmhz_matrix_evidence_hashes CHECK (
    raw_vector_sha256 REGEXP '^[0-9a-f]{64}$'
    AND (dictionary_formula_vector_sha256 IS NULL
      OR dictionary_formula_vector_sha256 REGEXP '^[0-9a-f]{64}$')
    AND (dictionary_cached_vector_sha256 IS NULL
      OR dictionary_cached_vector_sha256 REGEXP '^[0-9a-f]{64}$')
    AND row_hash REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_jmhz_matrix_evidence_members (
  catalog_id   BIGINT UNSIGNED NOT NULL,
  package_id   BIGINT UNSIGNED NOT NULL,
  axis_id      BIGINT UNSIGNED NOT NULL,
  attribute_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ordinal      INT UNSIGNED NOT NULL,
  source_cell  VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  raw_type     VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  raw_value    VARCHAR(8) NOT NULL,
  row_hash     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (axis_id, ordinal),
  UNIQUE KEY uq_jmhz_matrix_evidence_member_attribute (axis_id, attribute_id),
  CONSTRAINT fk_jmhz_matrix_evidence_member_axis
    FOREIGN KEY (axis_id, catalog_id, package_id)
    REFERENCES payroll_jmhz_matrix_evidence_axes(id, catalog_id, package_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_jmhz_matrix_evidence_member_attribute
    FOREIGN KEY (package_id, attribute_id)
    REFERENCES payroll_jmhz_dictionary_attributes(package_id, attribute_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_jmhz_matrix_evidence_member_value CHECK (
    raw_type IN ('n','s') AND raw_value = '1'
  ),
  CONSTRAINT chk_jmhz_matrix_evidence_member_hash CHECK (row_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_catalog_bu BEFORE UPDATE ON payroll_jmhz_scenario_catalogs
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ scenario catalogs are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_catalog_bd BEFORE DELETE ON payroll_jmhz_scenario_catalogs
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ scenario catalogs are append-only'; END//

CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_definition_bi BEFORE INSERT ON payroll_jmhz_scenario_definitions
FOR EACH ROW BEGIN
  IF (SELECT COUNT(*) FROM payroll_jmhz_scenario_definitions WHERE catalog_id = NEW.catalog_id)
      >= COALESCE((SELECT scenario_count FROM payroll_jmhz_scenario_catalogs WHERE id = NEW.catalog_id), 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ scenario catalog already contains all scenarios';
  END IF;
END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_definition_bu BEFORE UPDATE ON payroll_jmhz_scenario_definitions
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ scenario definitions are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_definition_bd BEFORE DELETE ON payroll_jmhz_scenario_definitions
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ scenario definitions are append-only'; END//

CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_interaction_bi BEFORE INSERT ON payroll_jmhz_interaction_definitions
FOR EACH ROW BEGIN
  IF (SELECT COUNT(*) FROM payroll_jmhz_interaction_definitions WHERE catalog_id = NEW.catalog_id)
      >= COALESCE((SELECT interaction_count FROM payroll_jmhz_scenario_catalogs WHERE id = NEW.catalog_id), 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ scenario catalog already contains all interactions';
  END IF;
END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_interaction_bu BEFORE UPDATE ON payroll_jmhz_interaction_definitions
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ interaction definitions are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_interaction_bd BEFORE DELETE ON payroll_jmhz_interaction_definitions
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ interaction definitions are append-only'; END//

CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_matrix_bi BEFORE INSERT ON payroll_jmhz_requirement_matrices
FOR EACH ROW BEGIN
  IF (SELECT COUNT(*) FROM payroll_jmhz_requirement_matrices WHERE catalog_id = NEW.catalog_id)
      >= COALESCE((SELECT matrix_count FROM payroll_jmhz_scenario_catalogs WHERE id = NEW.catalog_id), 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ scenario catalog already contains all matrices';
  END IF;
END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_matrix_bu BEFORE UPDATE ON payroll_jmhz_requirement_matrices
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ requirement matrices are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_matrix_bd BEFORE DELETE ON payroll_jmhz_requirement_matrices
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ requirement matrices are append-only'; END//

CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_requirement_bi BEFORE INSERT ON payroll_jmhz_field_requirements
FOR EACH ROW BEGIN
  IF (SELECT COUNT(*) FROM payroll_jmhz_field_requirements WHERE catalog_id = NEW.catalog_id)
      >= COALESCE((SELECT requirement_count FROM payroll_jmhz_scenario_catalogs WHERE id = NEW.catalog_id), 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ scenario catalog already contains all requirements';
  END IF;
END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_requirement_bu BEFORE UPDATE ON payroll_jmhz_field_requirements
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ field requirements are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_requirement_bd BEFORE DELETE ON payroll_jmhz_field_requirements
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ field requirements are append-only'; END//

CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_master_axis_bi BEFORE INSERT ON payroll_jmhz_master_attribute_axis
FOR EACH ROW BEGIN
  IF (SELECT COUNT(*) FROM payroll_jmhz_master_attribute_axis WHERE catalog_id = NEW.catalog_id)
      >= COALESCE((SELECT attribute_axis_count FROM payroll_jmhz_scenario_catalogs
                    WHERE id = NEW.catalog_id), 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ scenario catalog already contains the master axis';
  END IF;
END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_master_axis_bu BEFORE UPDATE ON payroll_jmhz_master_attribute_axis
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ master attribute axis is immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_master_axis_bd BEFORE DELETE ON payroll_jmhz_master_attribute_axis
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ master attribute axis is append-only'; END//

CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_evidence_axis_bi BEFORE INSERT ON payroll_jmhz_matrix_evidence_axes
FOR EACH ROW BEGIN
  IF (SELECT COUNT(*) FROM payroll_jmhz_matrix_evidence_axes WHERE catalog_id = NEW.catalog_id)
      >= COALESCE((SELECT evidence_axis_count FROM payroll_jmhz_scenario_catalogs WHERE id = NEW.catalog_id), 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ scenario catalog already contains all evidence axes';
  END IF;
END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_evidence_axis_bu BEFORE UPDATE ON payroll_jmhz_matrix_evidence_axes
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ matrix evidence axes are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_evidence_axis_bd BEFORE DELETE ON payroll_jmhz_matrix_evidence_axes
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ matrix evidence axes are append-only'; END//

CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_evidence_member_bi BEFORE INSERT ON payroll_jmhz_matrix_evidence_members
FOR EACH ROW BEGIN
  IF COALESCE((SELECT axis_kind FROM payroll_jmhz_matrix_evidence_axes WHERE id = NEW.axis_id), '')
      <> 'derived_binary' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only derived JMHZ evidence axes have sparse members';
  ELSEIF (SELECT COUNT(*) FROM payroll_jmhz_matrix_evidence_members WHERE axis_id = NEW.axis_id)
      >= COALESCE((SELECT one_count FROM payroll_jmhz_matrix_evidence_axes WHERE id = NEW.axis_id), 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ evidence axis already contains all sparse members';
  END IF;
END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_evidence_member_bu BEFORE UPDATE ON payroll_jmhz_matrix_evidence_members
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ matrix evidence members are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_evidence_member_bd BEFORE DELETE ON payroll_jmhz_matrix_evidence_members
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ matrix evidence members are append-only'; END//

CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_interaction_ref_bi BEFORE INSERT ON payroll_jmhz_interaction_attribute_refs
FOR EACH ROW BEGIN
  IF (SELECT COUNT(*) FROM payroll_jmhz_interaction_attribute_refs WHERE catalog_id = NEW.catalog_id)
      >= COALESCE((SELECT interaction_attribute_ref_count FROM payroll_jmhz_scenario_catalogs
                    WHERE id = NEW.catalog_id), 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ scenario catalog already contains all interaction refs';
  END IF;
END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_interaction_ref_bu BEFORE UPDATE ON payroll_jmhz_interaction_attribute_refs
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ interaction attribute refs are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_jmhz_scn_interaction_ref_bd BEFORE DELETE ON payroll_jmhz_interaction_attribute_refs
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'JMHZ interaction attribute refs are append-only'; END//

DELIMITER ;
