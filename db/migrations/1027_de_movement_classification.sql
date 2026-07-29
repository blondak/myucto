-- MyÚčto.cz — Epic DE (daňová evidence OSVČ): ruční klasifikační override pohybů.
--
-- Jediná odůvodněná nová tabulka celého epicu (viz PLAN §3). `bank_posting_rules`
-- (1020) jsou de facto double_entry-only (klasifikují do MD/D kontace pro journal),
-- takže v režimu `tax_evidence` neexistuje mechanismus, jak trvale přiřadit nezařazený
-- pohyb (bankovní transakci / pokladní doklad) do daňového/nedaňového kbelíku dle
-- §7b/§23 ZDP. Override tabulka to řeší — samotné movement tabulky
-- (cash_documents / bank_transactions) se NEMĚNÍ (pravidlo 1020 R1). Override je
-- sparse: jen pohyby, které auto-klasifikace neurčí nebo je uživatel ručně přeřadil.
--
-- Tenant scoping: `supplier_id` je vždy vyplněn (FK na supplier). Bankovní transakce
-- NEMAJÍ `supplier_id` (tenant se u nich řeší account-number matchem v aplikaci, R4),
-- proto na `bank_transaction_id` NENÍ FK; na `cash_document_id` FK je (cash_documents
-- supplier_id má), ON DELETE CASCADE.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS. Styl dle 1019/1022 (SET NAMES utf8mb4,
-- InnoDB, utf8mb4_unicode_ci).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS de_movement_classification (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  source_type         ENUM('bank','cash') NOT NULL,
  bank_transaction_id BIGINT UNSIGNED NULL,
  cash_document_id    BIGINT UNSIGNED NULL,
  tax_bucket          ENUM(
                        'income_taxable','income_exempt','income_nontax',
                        'expense_taxable','expense_nontax','transfer','private'
                      ) NOT NULL COMMENT 'cílový daňový kbelík dle §7b/§23',
  note                VARCHAR(255) NULL,
  classified_by       INT UNSIGNED NULL COMMENT 'user id',
  classified_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_de_cls_bank (supplier_id, bank_transaction_id),
  UNIQUE KEY uq_de_cls_cash (supplier_id, cash_document_id),
  KEY idx_de_cls_supplier (supplier_id),
  CONSTRAINT fk_de_cls_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_de_cls_cash FOREIGN KEY (cash_document_id) REFERENCES cash_documents(id) ON DELETE CASCADE,
  -- XOR na ID sloupcích + vazba na source_type: 'bank' ⇒ jen bank_transaction_id,
  -- 'cash' ⇒ jen cash_document_id. Zabraňuje nekonzistenci (např. source_type='bank'
  -- s vyplněným cash_document_id).
  CONSTRAINT chk_de_cls_xor CHECK (
    (source_type = 'bank' AND bank_transaction_id IS NOT NULL AND cash_document_id IS NULL) OR
    (source_type = 'cash' AND cash_document_id IS NOT NULL AND bank_transaction_id IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Guarded ALTER (idempotentní, native IF EXISTS — MariaDB 10.5.2+): dotáhne
-- zpřísněný CHECK i na již existující dev tabulku (migrace 1027 je tam applied,
-- takže se soubor znovu nespustí). Na čerstvé instalaci je to no-op vůči CREATE výše.
ALTER TABLE de_movement_classification DROP CONSTRAINT IF EXISTS chk_de_cls_xor;
ALTER TABLE de_movement_classification ADD CONSTRAINT chk_de_cls_xor CHECK (
  (source_type = 'bank' AND bank_transaction_id IS NOT NULL AND cash_document_id IS NULL) OR
  (source_type = 'cash' AND cash_document_id IS NOT NULL AND bank_transaction_id IS NULL)
);
