-- MyÚčto.cz — MZ-16: tenantově ověřený append-only hook do obecného DMS.

SET NAMES utf8mb4;

ALTER TABLE payroll_generated_documents
  DROP COLUMN IF EXISTS dms_document_id;

CREATE TABLE IF NOT EXISTS payroll_document_dms_links (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  payroll_document_id   BIGINT UNSIGNED NOT NULL,
  dms_document_id       BIGINT UNSIGNED NOT NULL,
  linked_by             BIGINT UNSIGNED NULL,
  linked_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_document_dms_link (supplier_id, payroll_document_id),
  UNIQUE KEY uq_payroll_document_dms_target (supplier_id, dms_document_id),
  CONSTRAINT fk_payroll_document_dms_payroll
    FOREIGN KEY (supplier_id, payroll_document_id)
    REFERENCES payroll_generated_documents (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_dms_document
    FOREIGN KEY (dms_document_id) REFERENCES documents (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_dms_user
    FOREIGN KEY (linked_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_document_dms_tenant_insert
BEFORE INSERT ON payroll_document_dms_links
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM documents document
     WHERE document.id = NEW.dms_document_id
       AND document.supplier_id = NEW.supplier_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll DMS link tenant mismatch';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_document_dms_immutable_update
BEFORE UPDATE ON payroll_document_dms_links
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll DMS links are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_document_dms_immutable_delete
BEFORE DELETE ON payroll_document_dms_links
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll DMS links are append-only';
END//

DELIMITER ;
