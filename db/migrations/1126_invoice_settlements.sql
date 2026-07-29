-- MyÚčto.cz — úhrada faktury zápočtem proti zvolenému účtu (typicky 355/365).
--
-- Případ z praxe: faktura se nevyrovná penězi, ale zápočtem proti pohledávce či
-- závazku za společníkem (355 / 365). Na rozdíl od offset_agreements (1046), který
-- páruje vzájemné doklady FV↔PF téhož partnera, tady stojí proti faktuře jeden
-- ZVOLENÝ rozvahový účet — protistrana není doklad, ale analytika.
--
-- invoice_settlements = jedna úhrada zápočtem. Po vytvoření vznikne idempotentní
-- účetní zápis (source_type='settlement', source_id = invoice_settlements.id):
--   vydaná faktura  → <zvolený účet> MD / 311 D   (default 355)
--   přijatá faktura → 321 MD / <zvolený účet> D   (default 365)
-- U vydané faktury se navíc zapíše invoice_payments řádek (source='settlement'),
-- takže částečné úhrady i paid_total fungují stejně jako u banky a pokladny.
-- Přijaté faktury nemají paid_total → jen plná výše, status → 'paid' (vzor 1019).
--
-- Storno: zápis dostane protizápis, platba se smaže, status se vrátí; hlavička
-- zůstává se status='cancelled' kvůli auditní stopě (vzor offset_agreements).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS + MODIFY (append-only ENUM).

SET NAMES utf8mb4;

-- journal_entries je system-versioned (1029) → MODIFY sloupce vyžaduje KEEP historie.
SET @@system_versioning_alter_history = 1;

-- Nový source_type pro zápočet proti účtu (append na konec ENUM).
-- POZOR: MODIFY nesmí vynechat žádnou existující hodnotu (jinak by se DROPla) —
-- kompletní seznam z 1100 + nová 'settlement'.
ALTER TABLE journal_entries
  MODIFY COLUMN source_type
  ENUM('invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
       'depreciation','asset_disposal','fx_revaluation','stock',
       'provision','income_tax','profit_distribution','offset','small_asset_accrual',
       'prepaid_expense_accrual','settlement')
  NOT NULL DEFAULT 'manual';

-- Provenience úhrady v invoice_payments (append na konec ENUM z 0108 + 1019).
-- Zápočet dosud jezdil jako 'manual' (OffsetService) — nová hodnota ho odliší.
ALTER TABLE invoice_payments
  MODIFY COLUMN source
  ENUM('manual','mark_paid','bank','legacy','cash','settlement')
  NOT NULL DEFAULT 'manual';

CREATE TABLE IF NOT EXISTS invoice_settlements (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  doc_type             ENUM('invoice','purchase_invoice') NOT NULL,
  doc_id               BIGINT UNSIGNED NOT NULL COMMENT 'invoices.id / purchase_invoices.id',
  settled_on           DATE NOT NULL COMMENT 'datum úhrady = datum účetního případu',
  amount               DECIMAL(15,2) NOT NULL COMMENT 'započtená částka (CZK, kladná)',
  account_id           BIGINT UNSIGNED NOT NULL COMMENT 'protiúčet zápočtu (chart_of_accounts.id)',
  note                 VARCHAR(500) NULL,
  status               ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  journal_entry_id     BIGINT UNSIGNED NULL COMMENT 'zaúčtování (NULL v daňové evidenci)',
  reversal_entry_id    BIGINT UNSIGNED NULL COMMENT 'protizápis po stornu',
  invoice_payment_id   BIGINT UNSIGNED NULL COMMENT 'u vydané faktury: vytvořená platba (pro storno)',
  created_by           INT NULL COMMENT 'user id (evidenční, bez FK — vzor assets.created_by)',
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_is_supplier (supplier_id, status, settled_on),
  KEY idx_is_doc (supplier_id, doc_type, doc_id),
  CONSTRAINT fk_is_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_is_account  FOREIGN KEY (account_id)  REFERENCES chart_of_accounts(id),
  CONSTRAINT fk_is_entry    FOREIGN KEY (journal_entry_id)  REFERENCES journal_entries(id) ON DELETE SET NULL,
  CONSTRAINT fk_is_reversal FOREIGN KEY (reversal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kontace zápočtu proti účtu (per-tenant override možný). Protiúčet z těchto pravidel
-- slouží jen jako PŘEDVOLBA v UI — skutečný účet si volí účetní u každé úhrady.
-- Vzor idempotentního insertu z 1006_.
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'payment.receivable.settlement', 'Úhrada vydané faktury zápočtem (355/311)', '355', '311', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'payment.receivable.settlement');

INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'payment.payable.settlement', 'Úhrada přijaté faktury zápočtem (321/365)', '321', '365', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'payment.payable.settlement');
