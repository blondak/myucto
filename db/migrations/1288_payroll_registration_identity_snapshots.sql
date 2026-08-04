-- MyÚčto.cz — MZ-21: neměnný citlivý snapshot identity PREZEC/REGZEC.
--
-- Tabulka neobsahuje plaintext osobních ani externích identifikátorů.
-- Snapshot je kontextově šifrovaný a veřejný manifest drží jen zdrojové
-- verze, keyed fingerprint a kryptografické otisky.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_registration_identity_snapshots (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id              INT UNSIGNED NOT NULL,
  environment              ENUM('production','test') NOT NULL,
  submission_id            BIGINT UNSIGNED NOT NULL,
  source_revision_id       BIGINT UNSIGNED NOT NULL,
  employee_id              BIGINT UNSIGNED NOT NULL,
  employment_id            BIGINT UNSIGNED NOT NULL,
  agenda_code              ENUM('PREZEC26','REGZEC25') NOT NULL,
  effective_on             DATE NOT NULL,
  schema_reference         VARCHAR(96) NOT NULL,
  source_manifest_json     MEDIUMTEXT NOT NULL,
  source_manifest_hash     CHAR(64) NOT NULL,
  snapshot_ciphertext      MEDIUMTEXT NOT NULL,
  snapshot_fingerprint     CHAR(64) NOT NULL,
  request_fingerprint      CHAR(64) NOT NULL,
  idempotency_key_hash     BINARY(32) NOT NULL,
  created_by               BIGINT UNSIGNED NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_registration_identity_snapshot_supplier
    (supplier_id, id),
  UNIQUE KEY uq_payroll_registration_identity_snapshot_environment
    (supplier_id, environment, id),
  UNIQUE KEY uq_payroll_registration_identity_snapshot_scope
    (
      supplier_id, environment, submission_id, source_revision_id,
      employment_id
    ),
  UNIQUE KEY uq_payroll_registration_identity_snapshot_idempotency
    (supplier_id, environment, idempotency_key_hash),
  KEY idx_payroll_registration_identity_snapshot_person
    (supplier_id, employee_id, employment_id, effective_on),

  CONSTRAINT fk_payroll_registration_identity_snapshot_submission
    FOREIGN KEY (supplier_id, environment, submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_registration_identity_snapshot_revision
    FOREIGN KEY (supplier_id, source_revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_registration_identity_snapshot_employment
    FOREIGN KEY (supplier_id, employment_id, employee_id)
    REFERENCES payroll_employments (supplier_id, id, employee_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_registration_identity_snapshot_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,

  CONSTRAINT chk_payroll_registration_identity_snapshot_schema
    CHECK (
      schema_reference = 'payroll-registration-identity-snapshot.v1'
    ),
  CONSTRAINT chk_payroll_registration_identity_snapshot_ciphertext
    CHECK (snapshot_ciphertext LIKE 'enc:v2:%'),
  CONSTRAINT chk_payroll_registration_identity_snapshot_hashes
    CHECK (
      source_manifest_hash REGEXP '^[0-9a-f]{64}$'
      AND snapshot_fingerprint REGEXP '^[0-9a-f]{64}$'
      AND request_fingerprint REGEXP '^[0-9a-f]{64}$'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_registration_identity_snapshot_no_update//
CREATE TRIGGER trg_payroll_registration_identity_snapshot_no_update
BEFORE UPDATE ON payroll_registration_identity_snapshots
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT =
      'payroll_registration_identity_snapshots are immutable';
END//

DROP TRIGGER IF EXISTS trg_payroll_registration_identity_snapshot_no_delete//
CREATE TRIGGER trg_payroll_registration_identity_snapshot_no_delete
BEFORE DELETE ON payroll_registration_identity_snapshots
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT =
      'payroll_registration_identity_snapshots are immutable';
END//

DELIMITER ;
