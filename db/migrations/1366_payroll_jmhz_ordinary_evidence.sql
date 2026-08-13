-- MZ-22-W03c: immutable explicit evidence for the ordinary scenario_1 profile.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_jmhz_ordinary_evidence_snapshots (
  id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id               INT UNSIGNED NOT NULL,
  run_id                    BIGINT UNSIGNED NOT NULL,
  source_revision_id        BIGINT UNSIGNED NOT NULL,
  employee_id               BIGINT UNSIGNED NOT NULL,
  employment_id             BIGINT UNSIGNED NOT NULL,
  period_start              DATE NOT NULL,
  schema_reference          VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_manifest_json      MEDIUMTEXT NOT NULL CHECK (JSON_VALID(source_manifest_json)),
  source_manifest_sha256    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  snapshot_ciphertext       LONGTEXT NOT NULL,
  snapshot_fingerprint      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_fingerprint       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  idempotency_key_hash      BINARY(32) NOT NULL,
  confirmed_by              BIGINT UNSIGNED NOT NULL,
  confirmed_at              DATETIME(6) NOT NULL,
  created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_jmhz_ordinary_supplier_id
    (supplier_id, id),
  UNIQUE KEY uq_payroll_jmhz_ordinary_source
    (supplier_id, source_revision_id, employee_id, employment_id),
  UNIQUE KEY uq_payroll_jmhz_ordinary_request
    (supplier_id, request_fingerprint),
  KEY idx_payroll_jmhz_ordinary_run
    (supplier_id, run_id, source_revision_id),

  CONSTRAINT fk_payroll_jmhz_ordinary_revision
    FOREIGN KEY (supplier_id, source_revision_id, run_id)
    REFERENCES payroll_run_revisions (supplier_id, id, run_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_ordinary_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_ordinary_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_ordinary_confirmer
    FOREIGN KEY (confirmed_by) REFERENCES users (id) ON DELETE RESTRICT,

  CONSTRAINT chk_payroll_jmhz_ordinary_period
    CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_jmhz_ordinary_schema
    CHECK (schema_reference = 'payroll-jmhz-ordinary-evidence.v1'),
  CONSTRAINT chk_payroll_jmhz_ordinary_hashes
    CHECK (
      source_manifest_sha256 REGEXP '^[0-9a-f]{64}$'
      AND snapshot_fingerprint REGEXP '^[0-9a-f]{64}$'
      AND request_fingerprint REGEXP '^[0-9a-f]{64}$'
    ),
  CONSTRAINT chk_payroll_jmhz_ordinary_ciphertext
    CHECK (snapshot_ciphertext LIKE 'enc:v2:%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_jmhz_ordinary_evidence_idempotency_claims (
  id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id               INT UNSIGNED NOT NULL,
  idempotency_key_hash      BINARY(32) NOT NULL,
  source_revision_id        BIGINT UNSIGNED NOT NULL,
  employee_id               BIGINT UNSIGNED NOT NULL,
  employment_id             BIGINT UNSIGNED NOT NULL,
  confirmation_fingerprint  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  evidence_snapshot_id      BIGINT UNSIGNED NULL,
  created_by                BIGINT UNSIGNED NOT NULL,
  created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_jmhz_ordinary_claim_scope
    (supplier_id, idempotency_key_hash),
  KEY idx_payroll_jmhz_ordinary_claim_revision
    (supplier_id, source_revision_id, employee_id, employment_id),

  CONSTRAINT fk_payroll_jmhz_ordinary_claim_revision
    FOREIGN KEY (supplier_id, source_revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_ordinary_claim_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_ordinary_claim_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_ordinary_claim_snapshot
    FOREIGN KEY (supplier_id, evidence_snapshot_id)
    REFERENCES payroll_jmhz_ordinary_evidence_snapshots (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_ordinary_claim_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_jmhz_ordinary_claim_confirmation
    CHECK (confirmation_fingerprint REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_jmhz_preparation_snapshots
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_preparation_builder;

ALTER TABLE payroll_jmhz_preparation_snapshots
  ADD CONSTRAINT chk_payroll_jmhz_preparation_builder CHECK (
    builder_version IN (
      'jmhz-preparation-source.v1',
      'jmhz-preparation-source.v2',
      'jmhz-preparation-source.v3',
      'jmhz-preparation-source.v4',
      'jmhz-preparation-source.v5'
    )
  );

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_jmhz_ordinary_insert_guard//
CREATE TRIGGER trg_payroll_jmhz_ordinary_insert_guard
BEFORE INSERT ON payroll_jmhz_ordinary_evidence_snapshots
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_run_revisions revision
      JOIN payroll_runs run
        ON run.supplier_id = revision.supplier_id
       AND run.id = revision.run_id
      JOIN payroll_run_employments frozen_employment
        ON frozen_employment.supplier_id = revision.supplier_id
       AND frozen_employment.revision_id = revision.id
       AND frozen_employment.employee_id = NEW.employee_id
       AND frozen_employment.employment_id = NEW.employment_id
     WHERE revision.supplier_id = NEW.supplier_id
       AND revision.id = NEW.source_revision_id
       AND revision.run_id = NEW.run_id
       AND revision.status = 'approved'
       AND revision.revision_kind = 'regular'
       AND revision.revision_no = run.current_revision_no
       AND run.period_start = NEW.period_start
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'JMHZ ordinary evidence requires current approved regular revision';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_ordinary_no_update//
CREATE TRIGGER trg_payroll_jmhz_ordinary_no_update
BEFORE UPDATE ON payroll_jmhz_ordinary_evidence_snapshots
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'payroll_jmhz_ordinary_evidence_snapshots are immutable';
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_ordinary_no_delete//
CREATE TRIGGER trg_payroll_jmhz_ordinary_no_delete
BEFORE DELETE ON payroll_jmhz_ordinary_evidence_snapshots
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'payroll_jmhz_ordinary_evidence_snapshots are immutable';
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_ordinary_claim_bind_once//
CREATE TRIGGER trg_payroll_jmhz_ordinary_claim_bind_once
BEFORE UPDATE ON payroll_jmhz_ordinary_evidence_idempotency_claims
FOR EACH ROW
BEGIN
  IF OLD.evidence_snapshot_id IS NOT NULL
     OR NEW.evidence_snapshot_id IS NULL
     OR NOT (NEW.supplier_id <=> OLD.supplier_id)
     OR NOT (NEW.idempotency_key_hash <=> OLD.idempotency_key_hash)
     OR NOT (NEW.source_revision_id <=> OLD.source_revision_id)
     OR NOT (NEW.employee_id <=> OLD.employee_id)
     OR NOT (NEW.employment_id <=> OLD.employment_id)
     OR NOT (NEW.confirmation_fingerprint <=> OLD.confirmation_fingerprint)
     OR NOT (NEW.created_by <=> OLD.created_by)
     OR NOT (NEW.created_at <=> OLD.created_at)
     OR NOT EXISTS (
       SELECT 1
         FROM payroll_jmhz_ordinary_evidence_snapshots evidence
        WHERE evidence.supplier_id = NEW.supplier_id
          AND evidence.id = NEW.evidence_snapshot_id
          AND evidence.source_revision_id = NEW.source_revision_id
          AND evidence.employee_id = NEW.employee_id
          AND evidence.employment_id = NEW.employment_id
     )
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'JMHZ ordinary evidence claim is single-assignment';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_ordinary_claim_no_delete//
CREATE TRIGGER trg_payroll_jmhz_ordinary_claim_no_delete
BEFORE DELETE ON payroll_jmhz_ordinary_evidence_idempotency_claims
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'payroll_jmhz_ordinary_evidence_idempotency_claims are immutable';
END//

DELIMITER ;
