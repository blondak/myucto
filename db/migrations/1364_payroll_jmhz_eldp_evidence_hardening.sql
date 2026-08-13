-- MyUcto.cz - MZ-22-W02c: hardening an already installed draft ELDP evidence schema.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_eldp_idempotency_claims
  ADD COLUMN IF NOT EXISTS confirmation_fingerprint
    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

ALTER TABLE payroll_jmhz_eldp_evidence_snapshots
  MODIFY COLUMN created_by BIGINT UNSIGNED NOT NULL;

ALTER TABLE payroll_jmhz_eldp_idempotency_claims
  MODIFY COLUMN created_by BIGINT UNSIGNED NOT NULL;

ALTER TABLE payroll_jmhz_eldp_idempotency_claims
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_eldp_claim_confirmation;

ALTER TABLE payroll_jmhz_eldp_idempotency_claims
  ADD CONSTRAINT chk_payroll_jmhz_eldp_claim_confirmation
    CHECK (confirmation_fingerprint REGEXP '^[0-9a-f]{64}$');

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_jmhz_eldp_insert_guard//
CREATE TRIGGER trg_payroll_jmhz_eldp_insert_guard
BEFORE INSERT ON payroll_jmhz_eldp_evidence_snapshots
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_run_revisions revision
      JOIN payroll_runs run
        ON run.supplier_id = revision.supplier_id
       AND run.id = revision.run_id
      JOIN payroll_employments employment
        ON employment.supplier_id = revision.supplier_id
       AND employment.id = NEW.employment_id
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
       AND employment.employee_id = NEW.employee_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ELDP evidence requires current approved regular revision';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_eldp_claim_bind_once//
CREATE TRIGGER trg_payroll_jmhz_eldp_claim_bind_once
BEFORE UPDATE ON payroll_jmhz_eldp_idempotency_claims
FOR EACH ROW
BEGIN
  IF OLD.evidence_snapshot_id IS NOT NULL
     OR NEW.evidence_snapshot_id IS NULL
     OR NOT (NEW.supplier_id <=> OLD.supplier_id)
     OR NOT (NEW.environment <=> OLD.environment)
     OR NOT (NEW.idempotency_key_hash <=> OLD.idempotency_key_hash)
     OR NOT (NEW.source_revision_id <=> OLD.source_revision_id)
     OR NOT (NEW.employment_id <=> OLD.employment_id)
     OR NOT (NEW.confirmation_fingerprint <=> OLD.confirmation_fingerprint)
     OR NOT (NEW.created_by <=> OLD.created_by)
     OR NOT (NEW.created_at <=> OLD.created_at)
     OR NOT EXISTS (
       SELECT 1
         FROM payroll_jmhz_eldp_evidence_snapshots evidence
        WHERE evidence.supplier_id = NEW.supplier_id
          AND evidence.environment = NEW.environment
          AND evidence.id = NEW.evidence_snapshot_id
          AND evidence.source_revision_id = NEW.source_revision_id
          AND evidence.employment_id = NEW.employment_id
     )
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ELDP evidence idempotency claim is single-assignment';
  END IF;
END//

DELIMITER ;
