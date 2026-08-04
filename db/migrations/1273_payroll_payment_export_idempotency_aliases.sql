-- MyÚčto.cz — MZ-17: trvalé idempotentní aliasy platebních exportů.

SET NAMES utf8mb4;

ALTER TABLE payroll_payment_exports
  ADD UNIQUE INDEX IF NOT EXISTS uq_payroll_payment_export_batch_id (
    supplier_id, batch_id, id
  );

CREATE TABLE IF NOT EXISTS payroll_payment_export_idempotency_keys (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  batch_id              BIGINT UNSIGNED NOT NULL,
  export_id             BIGINT UNSIGNED NOT NULL,
  idempotency_key_hash  BINARY(32) NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_payment_export_key (
    supplier_id, idempotency_key_hash
  ),
  KEY idx_payroll_payment_export_key_target (
    supplier_id, batch_id, export_id
  ),
  CONSTRAINT fk_payroll_payment_export_key_target
    FOREIGN KEY (supplier_id, batch_id, export_id)
    REFERENCES payroll_payment_exports (supplier_id, batch_id, id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO payroll_payment_export_idempotency_keys
  (supplier_id, batch_id, export_id, idempotency_key_hash)
SELECT supplier_id, batch_id, id, idempotency_key_hash
  FROM payroll_payment_exports;

DROP TRIGGER IF EXISTS trg_payroll_payment_export_key_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_payment_export_key_immutable_delete;

DELIMITER //

CREATE TRIGGER trg_payroll_payment_export_key_immutable_update
BEFORE UPDATE ON payroll_payment_export_idempotency_keys
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment export idempotency keys are immutable';
END//

CREATE TRIGGER trg_payroll_payment_export_key_immutable_delete
BEFORE DELETE ON payroll_payment_export_idempotency_keys
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment export idempotency keys are immutable';
END//

DELIMITER ;
