-- MyÚčto.cz — MZ-16: neměnný zdroj výstupních dokumentů při skončení vztahu.

SET NAMES utf8mb4;

ALTER TABLE payroll_employments
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_employment_owner
    (supplier_id, id, employee_id);

CREATE TABLE IF NOT EXISTS payroll_employment_exit_revisions (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  employment_id         BIGINT UNSIGNED NOT NULL,
  purpose               VARCHAR(48) NOT NULL,
  employment_end_date   DATE NOT NULL,
  revision_no           INT UNSIGNED NOT NULL,
  previous_revision_id  BIGINT UNSIGNED NULL,
  snapshot_ciphertext   LONGTEXT NOT NULL,
  snapshot_hash         CHAR(64) NOT NULL,
  source_manifest_json  LONGTEXT NOT NULL CHECK (JSON_VALID(source_manifest_json)),
  source_manifest_hash  CHAR(64) NOT NULL,
  approved_by           BIGINT UNSIGNED NULL,
  approved_at           DATETIME NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_employment_exit_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_employment_exit_sequence (
    supplier_id, employment_id, purpose, revision_no
  ),
  UNIQUE KEY uq_payroll_employment_exit_source (
    supplier_id, employment_id, purpose, source_manifest_hash
  ),
  KEY idx_payroll_employment_exit_latest (
    supplier_id, employment_id, purpose, revision_no
  ),
  CONSTRAINT fk_payroll_employment_exit_employment
    FOREIGN KEY (supplier_id, employment_id, employee_id)
    REFERENCES payroll_employments (supplier_id, id, employee_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_employment_exit_previous
    FOREIGN KEY (supplier_id, previous_revision_id)
    REFERENCES payroll_employment_exit_revisions (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_employment_exit_approver
    FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_employment_exit_purpose CHECK (
    purpose IN ('employment_certificate', 'average_earnings_certificate')
  ),
  CONSTRAINT chk_payroll_employment_exit_revision_number CHECK (
    revision_no > 0
  ),
  CONSTRAINT chk_payroll_employment_exit_hashes CHECK (
    snapshot_hash REGEXP '^[0-9a-f]{64}$'
    AND source_manifest_hash REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_payroll_employment_exit_revision_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_employment_exit_revision_immutable_delete;
DROP TRIGGER IF EXISTS trg_payroll_employment_exit_revision_validate_insert;

DELIMITER //

CREATE TRIGGER trg_payroll_employment_exit_revision_immutable_update
BEFORE UPDATE ON payroll_employment_exit_revisions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Employment exit document revisions are immutable';
END//

CREATE TRIGGER trg_payroll_employment_exit_revision_immutable_delete
BEFORE DELETE ON payroll_employment_exit_revisions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Employment exit document revisions are append-only';
END//

CREATE TRIGGER trg_payroll_employment_exit_revision_validate_insert
BEFORE INSERT ON payroll_employment_exit_revisions
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_employments employment
     WHERE employment.supplier_id = NEW.supplier_id
       AND employment.id = NEW.employment_id
       AND employment.employee_id = NEW.employee_id
       AND employment.status = 'ended'
       AND employment.end_date = NEW.employment_end_date
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Employment exit revision requires the matching ended employment';
  END IF;

  IF NEW.previous_revision_id IS NULL THEN
    IF NEW.revision_no <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'First employment exit revision must have revision number 1';
    END IF;
  ELSEIF NOT EXISTS (
    SELECT 1
      FROM payroll_employment_exit_revisions previous
     WHERE previous.supplier_id = NEW.supplier_id
       AND previous.id = NEW.previous_revision_id
       AND previous.employee_id = NEW.employee_id
       AND previous.employment_id = NEW.employment_id
       AND previous.purpose = NEW.purpose
       AND previous.employment_end_date = NEW.employment_end_date
       AND previous.revision_no + 1 = NEW.revision_no
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Employment exit revision chain is inconsistent';
  END IF;
END//

DELIMITER ;

ALTER TABLE payroll_generated_documents
  DROP FOREIGN KEY IF EXISTS fk_payroll_document_employment_exit_revision,
  DROP CONSTRAINT IF EXISTS chk_payroll_document_anchor,
  DROP INDEX IF EXISTS uq_payroll_document_employment_exit_revision;

ALTER TABLE payroll_generated_documents
  ADD COLUMN IF NOT EXISTS employment_exit_revision_id BIGINT UNSIGNED NULL
    AFTER annual_revision_id;

ALTER TABLE payroll_generated_documents
  ADD CONSTRAINT fk_payroll_document_employment_exit_revision
    FOREIGN KEY (supplier_id, employment_exit_revision_id)
    REFERENCES payroll_employment_exit_revisions (supplier_id, id)
    ON DELETE RESTRICT,
  ADD KEY IF NOT EXISTS idx_payroll_document_employment_exit_revision (
    supplier_id, employment_exit_revision_id
  ),
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_document_employment_exit_revision (
    supplier_id,
    employment_exit_revision_id,
    document_kind,
    employee_scope_id,
    document_revision_no
  ),
  ADD CONSTRAINT chk_payroll_document_anchor CHECK (
    (
      annual_revision_id IS NULL
      AND employment_exit_revision_id IS NULL
      AND run_id IS NOT NULL
      AND revision_id IS NOT NULL
    )
    OR
    (
      annual_revision_id IS NOT NULL
      AND employment_exit_revision_id IS NULL
      AND run_id IS NULL
      AND revision_id IS NULL
    )
    OR
    (
      annual_revision_id IS NULL
      AND employment_exit_revision_id IS NOT NULL
      AND run_id IS NULL
      AND revision_id IS NULL
    )
  );

DELIMITER //

CREATE OR REPLACE TRIGGER trg_payroll_document_approved_revision_insert
BEFORE INSERT ON payroll_generated_documents
FOR EACH ROW
BEGIN
  IF NEW.employment_exit_revision_id IS NOT NULL THEN
    IF NOT EXISTS (
      SELECT 1
        FROM payroll_employment_exit_revisions revision
       WHERE revision.supplier_id = NEW.supplier_id
         AND revision.id = NEW.employment_exit_revision_id
         AND revision.employee_id <=> NEW.employee_id
         AND revision.purpose = NEW.document_kind
         AND revision.snapshot_hash = NEW.revision_snapshot_hash
    ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll document requires an approved employment exit revision';
    END IF;
  ELSEIF NEW.annual_revision_id IS NOT NULL THEN
    IF NOT EXISTS (
      SELECT 1
        FROM payroll_annual_document_revisions revision
       WHERE revision.supplier_id = NEW.supplier_id
         AND revision.id = NEW.annual_revision_id
         AND revision.employee_id <=> NEW.employee_id
         AND revision.snapshot_hash = NEW.revision_snapshot_hash
    ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll document requires an approved annual revision';
    END IF;
  ELSE
    IF NOT EXISTS (
      SELECT 1
        FROM payroll_run_revisions revision
       WHERE revision.supplier_id = NEW.supplier_id
         AND revision.id = NEW.revision_id
         AND revision.run_id = NEW.run_id
         AND revision.status IN ('approved', 'superseded')
         AND revision.result_snapshot_hash = NEW.revision_snapshot_hash
    ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll document requires an approved matching revision';
    END IF;
  END IF;
END//

DELIMITER ;
