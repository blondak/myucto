-- MyÚčto.cz — Fáze F: vyžádání chybějících dokladů od klienta (audit 2026-07,
-- nález „Nejčastější zdržení měsíční uzávěrky je čekání na doklady od klienta;
-- dnes se urguje e-mailem mimo systém a nikdo neví, co už dorazilo").
--
-- document_requests = jeden požadavek účetní na chybějící doklad. Stav:
--   requested → uploaded (klient nahrál přes portál, AI vytěžila purchase_invoice)
--            → resolved (účetní zkontrolovala a uzavřela, případně bez uploadu)
--
-- Založení: ručně (formulář) nebo jedním klikem z nespárované bankovní transakce
-- (bank_transaction_id vyplněný — StatementDetail.vue akce „Vyžádat doklad").
-- Vyplnění: klientský portál nahraje soubor → reuse AiPdfExtractor::extractAndCreate
-- (stejná AI extrakce jako admin import) → purchase_invoice_id vyplní vzniklý doklad.
--
-- created_by/resolved_by = users.id s FK (na rozdíl od journal_entry_templates.created_by,
-- kde je FK záměrně vynechané — zde chceme referenční integritu i pro audit „kdo vyžádal").
--
-- Idempotence: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS document_requests (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  description          VARCHAR(500) NOT NULL COMMENT 'co chybí — popis pro klienta',
  amount               DECIMAL(14,2) NULL COMMENT 'kontext — částka platby/dokladu',
  context_date         DATE NULL COMMENT 'kontext — datum platby/dokladu',
  status               ENUM('requested','uploaded','resolved') NOT NULL DEFAULT 'requested',
  deadline             DATE NULL,
  bank_transaction_id  BIGINT UNSIGNED NULL COMMENT 'vazba na nespárovanou transakci, pokud požadavek vznikl odtud',
  purchase_invoice_id  BIGINT UNSIGNED NULL COMMENT 'doklad vzniklý z uploadu klienta (AI extrakce)',
  created_by           BIGINT UNSIGNED NULL COMMENT 'účetní, která požadavek založila',
  resolved_by          BIGINT UNSIGNED NULL,
  resolved_at          TIMESTAMP NULL,
  last_reminder_at     TIMESTAMP NULL COMMENT 'poslední automatická e-mailová urgence',
  reminder_count       INT UNSIGNED NOT NULL DEFAULT 0,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_dreq_supplier_status (supplier_id, status, deadline),
  KEY idx_dreq_bank_tx (bank_transaction_id),
  KEY idx_dreq_pi (purchase_invoice_id),
  KEY idx_dreq_reminder (status, last_reminder_at),
  CONSTRAINT fk_dreq_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_dreq_bank_tx FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_dreq_pi FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_dreq_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_dreq_resolved_by FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
