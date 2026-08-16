-- MyUcto.cz - MZ-24-W02: evidenční list důchodového pojištění jako samostatné podání.
--
-- Na rozdíl od payroll_jmhz_eldp_evidence_snapshots, které drží ELDP atributy
-- jednoho měsíce uvnitř měsíčního hlášení, je tohle celý evidenční list za
-- kalendářní rok (nebo jeho část při skončení účasti) — vlastní zákonná
-- povinnost s vlastní lhůtou podle § 38 a § 39 zákona č. 582/1991 Sb.
--
-- Snapshot je neměnný a šifrovaný; opakované sestavení téhož roku a vztahu
-- proto nemůže vzniknout dvakrát ani vytvořit druhé podání.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_eldp_statements (
  id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id               INT UNSIGNED NOT NULL,
  environment               ENUM('production','test') NOT NULL,
  employee_id               BIGINT UNSIGNED NOT NULL,
  employment_id             BIGINT UNSIGNED NOT NULL,
  statement_year            SMALLINT UNSIGNED NOT NULL,
  statement_kind            ENUM('annual','termination') NOT NULL,
  period_from               DATE NOT NULL,
  period_to                 DATE NOT NULL,
  schema_reference          VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  builder_version           VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  section_count             SMALLINT UNSIGNED NOT NULL,
  insurance_days            SMALLINT UNSIGNED NOT NULL,
  excluded_days_total       SMALLINT UNSIGNED NOT NULL,
  deducted_days_total       SMALLINT UNSIGNED NOT NULL,
  deadline_ruleset_id       VARCHAR(128) NOT NULL,
  deadline_ruleset_hash     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  earliest_submission_on    DATE NOT NULL,
  due_on                    DATE NOT NULL,
  xsd_package_key           VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  xsd_bundle_sha256         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  xml_sha256                CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_manifest_json      MEDIUMTEXT NOT NULL CHECK (JSON_VALID(source_manifest_json)),
  source_manifest_sha256    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  statement_ciphertext      LONGTEXT NOT NULL,
  statement_fingerprint     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_fingerprint       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  idempotency_key_hash      BINARY(32) NOT NULL,
  created_by                BIGINT UNSIGNED NOT NULL,
  created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_eldp_statement_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_eldp_statement_environment_id (supplier_id, environment, id),
  UNIQUE KEY uq_payroll_eldp_statement_scope
    (supplier_id, environment, employment_id, statement_year),
  UNIQUE KEY uq_payroll_eldp_statement_request
    (supplier_id, environment, request_fingerprint),
  KEY idx_payroll_eldp_statement_due (supplier_id, environment, due_on),

  CONSTRAINT fk_payroll_eldp_statement_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_eldp_statement_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_eldp_statement_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_eldp_statement_sources (
  id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id               INT UNSIGNED NOT NULL,
  environment               ENUM('production','test') NOT NULL,
  statement_id              BIGINT UNSIGNED NOT NULL,
  period_start              DATE NOT NULL,
  source_revision_id        BIGINT UNSIGNED NOT NULL,
  run_id                    BIGINT UNSIGNED NOT NULL,
  input_snapshot_hash       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  result_snapshot_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_eldp_source_month
    (supplier_id, environment, statement_id, period_start),
  KEY idx_payroll_eldp_source_revision (supplier_id, source_revision_id),

  CONSTRAINT fk_payroll_eldp_source_statement
    FOREIGN KEY (supplier_id, environment, statement_id)
    REFERENCES payroll_eldp_statements (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_eldp_source_revision
    FOREIGN KEY (supplier_id, source_revision_id, run_id)
    REFERENCES payroll_run_revisions (supplier_id, id, run_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_eldp_statement_claims (
  id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id               INT UNSIGNED NOT NULL,
  environment               ENUM('production','test') NOT NULL,
  idempotency_key_hash      BINARY(32) NOT NULL,
  employment_id             BIGINT UNSIGNED NOT NULL,
  statement_year            SMALLINT UNSIGNED NOT NULL,
  confirmation_fingerprint  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  statement_id              BIGINT UNSIGNED NULL,
  created_by                BIGINT UNSIGNED NOT NULL,
  created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_eldp_claim_scope
    (supplier_id, environment, idempotency_key_hash),
  KEY idx_payroll_eldp_claim_target (supplier_id, employment_id, statement_year),

  CONSTRAINT fk_payroll_eldp_claim_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_eldp_claim_statement
    FOREIGN KEY (supplier_id, environment, statement_id)
    REFERENCES payroll_eldp_statements (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_eldp_claim_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MariaDB neumí IF NOT EXISTS u CHECK ani u cizího klíče, takže se každé
-- omezení nejdřív zahodí a pak přidá zvlášť; migrace tím zůstane opakovatelná.

ALTER TABLE payroll_eldp_statements
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_statement_schema;
ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT chk_payroll_eldp_statement_schema
    CHECK (schema_reference = 'payroll-eldp-statement.v1');

ALTER TABLE payroll_eldp_statements
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_statement_builder;
ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT chk_payroll_eldp_statement_builder
    CHECK (builder_version = 'eldp-annual-statement.v1');

ALTER TABLE payroll_eldp_statements
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_statement_year;
ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT chk_payroll_eldp_statement_year
    CHECK (statement_year BETWEEN 2000 AND 2100);

ALTER TABLE payroll_eldp_statements
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_statement_period;
ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT chk_payroll_eldp_statement_period
    CHECK (
      period_to >= period_from
      AND YEAR(period_from) = statement_year
      AND YEAR(period_to) = statement_year
    );

ALTER TABLE payroll_eldp_statements
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_statement_sections;
ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT chk_payroll_eldp_statement_sections
    CHECK (section_count BETWEEN 1 AND 12);

ALTER TABLE payroll_eldp_statements
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_statement_days;
ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT chk_payroll_eldp_statement_days
    CHECK (
      insurance_days BETWEEN 1 AND 366
      AND excluded_days_total <= insurance_days
      AND deducted_days_total <= insurance_days
    );

ALTER TABLE payroll_eldp_statements
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_statement_deadline;
ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT chk_payroll_eldp_statement_deadline
    CHECK (due_on >= earliest_submission_on);

ALTER TABLE payroll_eldp_statements
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_statement_hashes;
ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT chk_payroll_eldp_statement_hashes
    CHECK (
      deadline_ruleset_hash REGEXP '^[0-9a-f]{64}$'
      AND xsd_bundle_sha256 REGEXP '^[0-9a-f]{64}$'
      AND xml_sha256 REGEXP '^[0-9a-f]{64}$'
      AND source_manifest_sha256 REGEXP '^[0-9a-f]{64}$'
      AND statement_fingerprint REGEXP '^[0-9a-f]{64}$'
      AND request_fingerprint REGEXP '^[0-9a-f]{64}$'
    );

ALTER TABLE payroll_eldp_statements
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_statement_ciphertext;
ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT chk_payroll_eldp_statement_ciphertext
    CHECK (statement_ciphertext LIKE 'enc:v2:%');

ALTER TABLE payroll_eldp_statement_sources
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_source_period;
ALTER TABLE payroll_eldp_statement_sources
  ADD CONSTRAINT chk_payroll_eldp_source_period
    CHECK (DAY(period_start) = 1);

ALTER TABLE payroll_eldp_statement_sources
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_source_hashes;
ALTER TABLE payroll_eldp_statement_sources
  ADD CONSTRAINT chk_payroll_eldp_source_hashes
    CHECK (
      input_snapshot_hash REGEXP '^[0-9a-f]{64}$'
      AND result_snapshot_hash REGEXP '^[0-9a-f]{64}$'
    );

ALTER TABLE payroll_eldp_statement_claims
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_claim_confirmation;
ALTER TABLE payroll_eldp_statement_claims
  ADD CONSTRAINT chk_payroll_eldp_claim_confirmation
    CHECK (confirmation_fingerprint REGEXP '^[0-9a-f]{64}$');

ALTER TABLE payroll_eldp_statement_claims
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_claim_year;
ALTER TABLE payroll_eldp_statement_claims
  ADD CONSTRAINT chk_payroll_eldp_claim_year
    CHECK (statement_year BETWEEN 2000 AND 2100);

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_eldp_statement_no_update//
CREATE TRIGGER trg_payroll_eldp_statement_no_update
BEFORE UPDATE ON payroll_eldp_statements
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'payroll_eldp_statements are immutable';
END//

DROP TRIGGER IF EXISTS trg_payroll_eldp_statement_no_delete//
CREATE TRIGGER trg_payroll_eldp_statement_no_delete
BEFORE DELETE ON payroll_eldp_statements
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'payroll_eldp_statements are immutable';
END//

DROP TRIGGER IF EXISTS trg_payroll_eldp_source_no_update//
CREATE TRIGGER trg_payroll_eldp_source_no_update
BEFORE UPDATE ON payroll_eldp_statement_sources
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'payroll_eldp_statement_sources are immutable';
END//

DROP TRIGGER IF EXISTS trg_payroll_eldp_source_no_delete//
CREATE TRIGGER trg_payroll_eldp_source_no_delete
BEFORE DELETE ON payroll_eldp_statement_sources
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'payroll_eldp_statement_sources are immutable';
END//

DROP TRIGGER IF EXISTS trg_payroll_eldp_claim_bind_once//
CREATE TRIGGER trg_payroll_eldp_claim_bind_once
BEFORE UPDATE ON payroll_eldp_statement_claims
FOR EACH ROW
BEGIN
  IF OLD.statement_id IS NOT NULL
     OR NEW.statement_id IS NULL
     OR NOT (NEW.supplier_id <=> OLD.supplier_id)
     OR NOT (NEW.environment <=> OLD.environment)
     OR NOT (NEW.idempotency_key_hash <=> OLD.idempotency_key_hash)
     OR NOT (NEW.employment_id <=> OLD.employment_id)
     OR NOT (NEW.statement_year <=> OLD.statement_year)
     OR NOT (NEW.confirmation_fingerprint <=> OLD.confirmation_fingerprint)
     OR NOT (NEW.created_by <=> OLD.created_by)
     OR NOT (NEW.created_at <=> OLD.created_at)
     OR NOT EXISTS (
       SELECT 1
         FROM payroll_eldp_statements statement
        WHERE statement.supplier_id = NEW.supplier_id
          AND statement.environment = NEW.environment
          AND statement.id = NEW.statement_id
          AND statement.employment_id = NEW.employment_id
          AND statement.statement_year = NEW.statement_year
     )
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ELDP statement idempotency claim is single-assignment';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_eldp_claim_no_delete//
CREATE TRIGGER trg_payroll_eldp_claim_no_delete
BEFORE DELETE ON payroll_eldp_statement_claims
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'payroll_eldp_statement_claims are immutable';
END//

DELIMITER ;
