-- MyÚčto.cz — elektronické předávání přijatých dokladů klientem.
--
-- Nahraný soubor nejprve vznikne jako samostatné staging podání nad DMS. Dokud
-- není podání účetní zpracované, neexistuje purchase_invoice, položky ani zápis
-- v evidenci DPH/účetnictví. Originál zůstává dohledatelný i při chybě extrakce.
--
-- Idempotence: nativní MariaDB IF NOT EXISTS + INSERT IGNORE.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_invoice_submissions (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id              INT UNSIGNED NOT NULL,
  document_id              BIGINT UNSIGNED NOT NULL,
  bank_transaction_id      BIGINT UNSIGNED NULL,
  supersedes_submission_id BIGINT UNSIGNED NULL,
  submitted_by             BIGINT UNSIGNED NULL,
  submitted_via            ENUM('portal','document_request','staff') NOT NULL DEFAULT 'portal',
  note                     TEXT NULL,
  document_kind_hint       ENUM('invoice','receipt','credit_note','advance','tax_document','other') NULL,
  document_sha256          CHAR(64) NOT NULL,
  status                   ENUM('submitted','processing','needs_information','processed','rejected')
                             NOT NULL DEFAULT 'submitted',
  status_reason            TEXT NULL,
  extraction_status        ENUM('not_started','running','succeeded','failed')
                             NOT NULL DEFAULT 'not_started',
  extraction_source        VARCHAR(32) NULL,
  extraction_error         TEXT NULL,
  purchase_invoice_id      BIGINT UNSIGNED NULL,
  processing_started_at    TIMESTAMP NULL,
  processed_at             TIMESTAMP NULL,
  processed_by             BIGINT UNSIGNED NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_pi_submission_supplier_sha (supplier_id, document_sha256),
  UNIQUE KEY uq_pi_submission_document (document_id),
  KEY idx_pi_submission_queue (supplier_id, status, created_at),
  KEY idx_pi_submission_invoice (purchase_invoice_id),
  KEY idx_pi_submission_bank_tx (bank_transaction_id),
  UNIQUE KEY uq_pi_submission_supersedes (supersedes_submission_id),

  CONSTRAINT fk_pi_submission_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_submission_document
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pi_submission_bank_tx
    FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_pi_submission_supersedes
    FOREIGN KEY (supersedes_submission_id) REFERENCES purchase_invoice_submissions(id) ON DELETE SET NULL,
  CONSTRAINT fk_pi_submission_submitted_by
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pi_submission_invoice
    FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_pi_submission_processed_by
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE document_requests
  ADD COLUMN IF NOT EXISTS submission_id BIGINT UNSIGNED NULL AFTER purchase_invoice_id,
  ADD KEY IF NOT EXISTS idx_dreq_submission (submission_id),
  ADD CONSTRAINT fk_dreq_submission
    FOREIGN KEY IF NOT EXISTS (submission_id)
    REFERENCES purchase_invoice_submissions(id) ON DELETE SET NULL;

-- Systémové role dostanou nové bezpečně oddělené schopnosti. Vlastní role jsou
-- záměrně fail-closed a správce jim právo přidělí explicitně v editoru rolí.
INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, permissions.permission_key, permissions.access_level
FROM roles r
JOIN (
  SELECT 'accountant' system_key, 'documents.inbox' permission_key, 2 access_level
  UNION ALL SELECT 'readonly', 'documents.inbox', 1
  UNION ALL SELECT 'client', 'documents.submit', 2
) permissions ON permissions.system_key = r.system_key;
