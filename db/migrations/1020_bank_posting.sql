-- MyÚčto.cz — Mini-epic AUTOMATIZACE: automatické zaúčtování bankovních transakcí
--
-- „Mysli jako účetní": spárované platby FV/PF se po importu samy zaúčtují
-- (221/311, 321/221) přes PostingService (source_type='bank', idempotence na
-- ('bank', bt.id) přes uq_je_supplier_source z 1007). Opakované platby bez dokladu
-- (OSSZ, ZP, FÚ, poplatky, úroky…) se od 2. výskytu účtují podle naučeného pravidla —
-- nejdřív jako návrh (suggest), po ověření volitelně plně automaticky (auto).
--
-- bank_transactions ani bank_statements se NEMĚNÍ (R1) — stav zaúčtování je vždy
-- JOIN na journal_entries (source_type='bank', source_id = bt.id).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, seed INSERT ... WHERE NOT EXISTS.

SET NAMES utf8mb4;

-- ── Pravidla účtování opakovaných transakcí (per-tenant, plná kontace MD/D) ──
CREATE TABLE IF NOT EXISTS bank_posting_rules (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,          -- vždy per-tenant, žádný globální seed
  name                 VARCHAR(120) NOT NULL,          -- „Odvod OSSZ", „Poplatky Fio"
  direction            ENUM('incoming','outgoing') NOT NULL,
  counterparty_account VARCHAR(35)  NULL,              -- AccountNumberNormalizer::normalize
  counterparty_bank    VARCHAR(10)  NULL,
  variable_symbol      VARCHAR(10)  NULL,              -- VariableSymbolNormalizer::digits
  message_contains     VARCHAR(120) NULL,              -- normalizovaný fragment (§4.1)
  amount_min           DECIMAL(14,2) NULL,             -- NULL = bez omezení (pro auto povinné, §5.2)
  amount_max           DECIMAL(14,2) NULL,
  debit_account_code   VARCHAR(10) NOT NULL,           -- plná kontace (R6)
  credit_account_code  VARCHAR(10) NOT NULL,
  description          VARCHAR(255) NULL,              -- text zápisu; NULL = popis z transakce
  mode                 ENUM('suggest','auto') NOT NULL DEFAULT 'suggest',
  is_active            TINYINT(1) NOT NULL DEFAULT 1,
  hit_count            INT UNSIGNED NOT NULL DEFAULT 0,
  last_hit_at          DATETIME NULL,
  rejected_streak      TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- R7; per distinct tx (M3)
  last_rejected_tx_id  BIGINT UNSIGNED NULL,                  -- M3: streak++ jen při jiné tx
  created_by           BIGINT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_bpr_supplier (supplier_id, is_active, direction),
  CONSTRAINT fk_bpr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_bpr_user     FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE SET NULL,
  -- aspoň jedno match kritérium (jinak by pravidlo chytalo všechno)
  CONSTRAINT chk_bpr_criteria CHECK (
    counterparty_account IS NOT NULL OR variable_symbol IS NOT NULL OR message_contains IS NOT NULL
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Fronta návrhů + protokol automatiky ────────────────────────────────────
CREATE TABLE IF NOT EXISTS bank_posting_suggestions (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  bank_transaction_id  BIGINT UNSIGNED NOT NULL,
  rule_id              BIGINT UNSIGNED NULL,           -- NULL u learned / payment_match
  source               ENUM('rule','learned','payment_match') NOT NULL,
  debit_account_code   VARCHAR(10) NOT NULL,
  credit_account_code  VARCHAR(10) NOT NULL,
  amount               DECIMAL(14,2) NOT NULL,         -- ABS(bt.amount)
  description          VARCHAR(255) NULL,
  status               ENUM('pending','approved','rejected','auto_posted','superseded')
                       NOT NULL DEFAULT 'pending',
  note                 VARCHAR(255) NULL,              -- period_closed, rule_conflict, looks_like:#id,
                                                       -- already_paid_verify, overwritten_by_match…
  journal_entry_id     BIGINT UNSIGNED NULL,           -- po approve/auto_posted
  reviewed_by          BIGINT UNSIGNED NULL,
  reviewed_at          DATETIME NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- M2: max 1 pending na transakci vynucuje DB, ne check-then-insert
  pending_tx           BIGINT UNSIGNED AS (IF(status = 'pending', bank_transaction_id, NULL)) PERSISTENT,
  UNIQUE KEY uq_bps_pending (pending_tx),

  KEY idx_bps_supplier_status (supplier_id, status),
  KEY idx_bps_tx (bank_transaction_id),
  KEY idx_bps_rule_status (rule_id, status, bank_transaction_id),   -- M3: reject lookup (tx, rule)
  CONSTRAINT fk_bps_supplier FOREIGN KEY (supplier_id)         REFERENCES supplier(id)           ON DELETE CASCADE,
  CONSTRAINT fk_bps_tx       FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id)  ON DELETE CASCADE,
  CONSTRAINT fk_bps_rule     FOREIGN KEY (rule_id)             REFERENCES bank_posting_rules(id) ON DELETE SET NULL,
  CONSTRAINT fk_bps_entry    FOREIGN KEY (journal_entry_id)    REFERENCES journal_entries(id)    ON DELETE SET NULL,
  CONSTRAINT fk_bps_reviewer FOREIGN KEY (reviewed_by)         REFERENCES users(id)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed prefill kontace bankovních poplatků (globální šablona, NOT EXISTS guard) ──
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'bank.fee', 'Bankovní poplatky', '568', '221', 0, 1
WHERE NOT EXISTS (
  SELECT 1 FROM posting_rules pr
  WHERE pr.supplier_id IS NULL AND pr.rule_key = 'bank.fee' AND pr.priority = 0
);
