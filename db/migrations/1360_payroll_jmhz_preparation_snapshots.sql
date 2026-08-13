-- MyUcto.cz - MZ-22-W02a: immutable encrypted JMHZ preparation evidence.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_jmhz_preparation_snapshots (
  id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id               INT UNSIGNED NOT NULL,
  environment               ENUM('production','test') NOT NULL,
  run_id                    BIGINT UNSIGNED NOT NULL,
  source_revision_id        BIGINT UNSIGNED NOT NULL,
  period_start              DATE NOT NULL,
  scenario_key              VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  builder_version           VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  readiness_status          ENUM('blocked','source_ready') NOT NULL,
  issue_count               INT UNSIGNED NOT NULL,
  source_manifest_json      MEDIUMTEXT NOT NULL CHECK (JSON_VALID(source_manifest_json)),
  source_manifest_sha256    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  readiness_json            MEDIUMTEXT NOT NULL CHECK (JSON_VALID(readiness_json)),
  readiness_sha256          CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  snapshot_ciphertext       LONGTEXT NOT NULL,
  snapshot_fingerprint      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_fingerprint       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  idempotency_key_hash      BINARY(32) NOT NULL,
  created_by                BIGINT UNSIGNED NULL,
  created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_jmhz_preparation_supplier_id
    (supplier_id, id),
  UNIQUE KEY uq_payroll_jmhz_preparation_environment_id
    (supplier_id, environment, id),
  UNIQUE KEY uq_payroll_jmhz_preparation_source
    (supplier_id, environment, source_revision_id, builder_version,
     source_manifest_sha256),
  UNIQUE KEY uq_payroll_jmhz_preparation_request
    (supplier_id, environment, request_fingerprint),
  UNIQUE KEY uq_payroll_jmhz_preparation_idempotency
    (supplier_id, environment, idempotency_key_hash),
  KEY idx_payroll_jmhz_preparation_run
    (supplier_id, run_id, source_revision_id),

  CONSTRAINT fk_payroll_jmhz_preparation_revision
    FOREIGN KEY (supplier_id, source_revision_id, run_id)
    REFERENCES payroll_run_revisions (supplier_id, id, run_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_preparation_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,

  CONSTRAINT chk_payroll_jmhz_preparation_period
    CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_jmhz_preparation_scenario
    CHECK (scenario_key = 'scenario_1'),
  CONSTRAINT chk_payroll_jmhz_preparation_builder
    CHECK (builder_version = 'jmhz-preparation-source.v1'),
  CONSTRAINT chk_payroll_jmhz_preparation_issues
    CHECK (
      (readiness_status = 'source_ready' AND issue_count = 0)
      OR (readiness_status = 'blocked' AND issue_count > 0)
    ),
  CONSTRAINT chk_payroll_jmhz_preparation_hashes
    CHECK (
      source_manifest_sha256 REGEXP '^[0-9a-f]{64}$'
      AND readiness_sha256 REGEXP '^[0-9a-f]{64}$'
      AND snapshot_fingerprint REGEXP '^[0-9a-f]{64}$'
      AND request_fingerprint REGEXP '^[0-9a-f]{64}$'
    ),
  CONSTRAINT chk_payroll_jmhz_preparation_ciphertext
    CHECK (snapshot_ciphertext LIKE 'enc:v2:%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_jmhz_preparation_insert_guard//
CREATE TRIGGER trg_payroll_jmhz_preparation_insert_guard
BEFORE INSERT ON payroll_jmhz_preparation_snapshots
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_run_revisions revision
      JOIN payroll_runs run
        ON run.supplier_id = revision.supplier_id
       AND run.id = revision.run_id
     WHERE revision.supplier_id = NEW.supplier_id
       AND revision.id = NEW.source_revision_id
       AND revision.run_id = NEW.run_id
       AND revision.status = 'approved'
       AND revision.revision_no = run.current_revision_no
       AND run.period_start = NEW.period_start
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'JMHZ preparation requires current approved revision';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_preparation_no_update//
CREATE TRIGGER trg_payroll_jmhz_preparation_no_update
BEFORE UPDATE ON payroll_jmhz_preparation_snapshots
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'payroll_jmhz_preparation_snapshots are immutable';
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_preparation_no_delete//
CREATE TRIGGER trg_payroll_jmhz_preparation_no_delete
BEFORE DELETE ON payroll_jmhz_preparation_snapshots
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'payroll_jmhz_preparation_snapshots are immutable';
END//

DELIMITER ;
