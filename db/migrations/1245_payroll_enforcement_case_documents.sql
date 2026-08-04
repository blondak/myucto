-- MyÚčto.cz — MZ-14: klasifikované a neměnné právní podklady přechodů.

SET NAMES utf8mb4;

ALTER TABLE documents
  ADD UNIQUE KEY IF NOT EXISTS uq_documents_supplier_id (supplier_id, id);

CREATE TABLE IF NOT EXISTS payroll_enforcement_case_documents (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  case_id            BIGINT UNSIGNED NOT NULL,
  dms_document_id    BIGINT UNSIGNED NOT NULL,
  evidence_kind      ENUM(
                       'initial_order','finality','remittance',
                       'deferment','resumption','termination'
                     ) NOT NULL,
  document_sha256    CHAR(64) NOT NULL,
  verified_by        BIGINT UNSIGNED NULL,
  verified_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_enforcement_case_document_id (supplier_id, id),
  KEY idx_payroll_enforcement_case_document_case
    (supplier_id, case_id, id),
  CONSTRAINT fk_payroll_enforcement_case_document_case
    FOREIGN KEY (supplier_id, case_id)
    REFERENCES payroll_enforcement_cases (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_case_document_dms
    FOREIGN KEY (dms_document_id)
    REFERENCES documents (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_case_document_user
    FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_enforcement_case_document_hash
    CHECK (document_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_enforcement_events
  ADD COLUMN IF NOT EXISTS decision_case_document_id BIGINT UNSIGNED NULL
    AFTER decision_document_id,
  ADD INDEX IF NOT EXISTS idx_payroll_enforcement_event_case_document
    (supplier_id, decision_case_document_id),
  DROP FOREIGN KEY IF EXISTS fk_payroll_enforcement_event_case_document;

ALTER TABLE payroll_enforcement_events
  ADD CONSTRAINT fk_payroll_enforcement_event_case_document
    FOREIGN KEY (supplier_id, decision_case_document_id)
    REFERENCES payroll_enforcement_case_documents (supplier_id, id)
    ON DELETE RESTRICT;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_case_document_insert
BEFORE INSERT ON payroll_enforcement_case_documents
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM documents document
     WHERE document.supplier_id = NEW.supplier_id
       AND document.id = NEW.dms_document_id
       AND document.deleted_at IS NULL
       AND document.sha256 = NEW.document_sha256
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement case document mismatch';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_case_document_immutable_update
BEFORE UPDATE ON payroll_enforcement_case_documents
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement case documents are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_case_document_immutable_delete
BEFORE DELETE ON payroll_enforcement_case_documents
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement case documents are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_documents_payroll_enforcement_evidence_update
BEFORE UPDATE ON documents
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_enforcement_case_documents case_document
     WHERE case_document.dms_document_id = OLD.id
  ) AND (
    NOT (NEW.supplier_id <=> OLD.supplier_id)
    OR NOT (NEW.sha256 <=> OLD.sha256)
    OR NOT (NEW.deleted_at <=> OLD.deleted_at)
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Document is retained as payroll enforcement evidence';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_enforcement_event_document_insert//

CREATE TRIGGER trg_payroll_enforcement_event_document_insert
BEFORE INSERT ON payroll_enforcement_events
FOR EACH ROW
BEGIN
  IF NEW.decision_document_id IS NULL
     AND (NEW.decision_evidence_hash IS NOT NULL
       OR NEW.decision_case_document_id IS NOT NULL) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement decision reference is incomplete';
  END IF;

  IF NEW.decision_document_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
      FROM payroll_enforcement_case_documents case_document
     WHERE case_document.supplier_id = NEW.supplier_id
       AND case_document.id = NEW.decision_case_document_id
       AND case_document.case_id = NEW.case_id
       AND case_document.dms_document_id = NEW.decision_document_id
       AND case_document.document_sha256 = NEW.decision_evidence_hash
       AND case_document.evidence_kind = CASE NEW.command_name
         WHEN 'mark_final' THEN 'initial_order'
         WHEN 'authorize_remittance' THEN 'remittance'
         WHEN 'defer_no_withholding' THEN 'deferment'
         WHEN 'defer_hold' THEN 'deferment'
         WHEN 'resume_holding' THEN 'resumption'
         WHEN 'resume_remittance' THEN 'resumption'
         WHEN 'stop' THEN 'termination'
         ELSE ''
       END
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement decision classification mismatch';
  END IF;
END//

DELIMITER ;
