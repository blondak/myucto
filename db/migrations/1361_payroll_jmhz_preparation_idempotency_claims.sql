-- MyUcto.cz - MZ-22-W02a: durable idempotency claims for JMHZ preparations.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_jmhz_preparation_idempotency_claims (
  id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id               INT UNSIGNED NOT NULL,
  environment               ENUM('production','test') NOT NULL,
  idempotency_key_hash      BINARY(32) NOT NULL,
  source_revision_id        BIGINT UNSIGNED NOT NULL,
  preparation_snapshot_id   BIGINT UNSIGNED NULL,
  created_by                BIGINT UNSIGNED NULL,
  created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_jmhz_preparation_claim_scope
    (supplier_id, environment, idempotency_key_hash),
  UNIQUE KEY uq_payroll_jmhz_preparation_claim_owner
    (supplier_id, environment, id),
  KEY idx_payroll_jmhz_preparation_claim_revision
    (supplier_id, source_revision_id),

  CONSTRAINT fk_payroll_jmhz_preparation_claim_revision
    FOREIGN KEY (supplier_id, source_revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_preparation_claim_snapshot
    FOREIGN KEY (supplier_id, environment, preparation_snapshot_id)
    REFERENCES payroll_jmhz_preparation_snapshots (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_preparation_claim_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_jmhz_preparation_claim_bind_once//
CREATE TRIGGER trg_payroll_jmhz_preparation_claim_bind_once
BEFORE UPDATE ON payroll_jmhz_preparation_idempotency_claims
FOR EACH ROW
BEGIN
  IF OLD.preparation_snapshot_id IS NOT NULL
     OR NEW.preparation_snapshot_id IS NULL
     OR NOT (NEW.supplier_id <=> OLD.supplier_id)
     OR NOT (NEW.environment <=> OLD.environment)
     OR NOT (NEW.idempotency_key_hash <=> OLD.idempotency_key_hash)
     OR NOT (NEW.source_revision_id <=> OLD.source_revision_id)
     OR NOT (NEW.created_by <=> OLD.created_by)
     OR NOT (NEW.created_at <=> OLD.created_at)
     OR NOT EXISTS (
       SELECT 1
         FROM payroll_jmhz_preparation_snapshots preparation
        WHERE preparation.supplier_id = NEW.supplier_id
          AND preparation.environment = NEW.environment
          AND preparation.id = NEW.preparation_snapshot_id
          AND preparation.source_revision_id = NEW.source_revision_id
     )
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'JMHZ preparation idempotency claim is single-assignment';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_jmhz_preparation_claim_no_delete//
CREATE TRIGGER trg_payroll_jmhz_preparation_claim_no_delete
BEFORE DELETE ON payroll_jmhz_preparation_idempotency_claims
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'payroll_jmhz_preparation_idempotency_claims are immutable';
END//

DELIMITER ;
