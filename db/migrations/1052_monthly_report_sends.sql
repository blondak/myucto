-- MyÚčto.cz — Fáze F: měsíční přehled klientovi (audit 2026-07, P3 návrh
-- „Měsíční report klientovi jedním klikem"). Log odeslání PDF balíčku
-- (výsledovka měsíc+YTD, rozvaha, saldokonto po splatnosti, DPH, termíny)
-- klientovi e-mailem + archivace do dokumentů firmy (DMS).
--
-- document_id ukazuje na archivovanou kopii PDF v `documents` (DMS) — NULL
-- pokud archivace selhala/byla vynechána, odeslání e-mailu tím není blokováno.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS monthly_report_sends (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id      INT UNSIGNED NOT NULL,
  report_year      SMALLINT UNSIGNED NOT NULL,
  report_month     TINYINT UNSIGNED NOT NULL,
  sent_to          JSON NOT NULL,
  cc               JSON NULL,
  comment          TEXT NULL,
  document_id      BIGINT UNSIGNED NULL COMMENT 'archivovaná kopie PDF v Dokumentech (DMS)',
  smtp_response    VARCHAR(255) NULL,
  sent_by_user_id  BIGINT UNSIGNED NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_mrs_supplier_period (supplier_id, report_year, report_month),
  CONSTRAINT fk_mrs_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_mrs_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL,
  CONSTRAINT fk_mrs_sent_by FOREIGN KEY (sent_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
