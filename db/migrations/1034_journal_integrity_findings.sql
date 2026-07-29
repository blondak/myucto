-- MyÚčto.cz — Fáze A/A6: noční integrity job nad účetním deníkem (audit 2026-07)
--
-- Uložený výsledek posledního běhu kontroly konzistence doklad ↔ deník. Čistě
-- DIAGNOSTICKÁ data — job nic neopravuje, jen hlásí. Dashboard (action item
-- `journal_integrity`) čte poslední uložený výsledek, aby se drahé plné sweepy
-- deníku nepočítaly při každém requestu na dashboard.
--
-- Jeden řádek per (supplier_id, finding_type) — každý běh cronu ho přepíše
-- (INSERT … ON DUPLICATE KEY UPDATE). finding_count = 0 znamená „zkontrolováno,
-- čisté" (drží se i tak, ať je vidět checked_at). detail = JSON vzorek prvních
-- N nálezů (capováno v JournalIntegrityService) pro rozklik/diagnostiku.
--
-- Typy nálezů:
--   orphan_entry          — aktivní zápis odkazuje na doklad, který neexistuje
--   unbalanced_entry      — Σ MD ≠ Σ D na řádcích jednoho zápisu (pojistka proti
--                           přímým DB zásahům; PostingService to jinak vynucuje)
--   booked_without_entry  — doklad má booked_at, ale žádný aktivní zápis
--   entry_without_booked  — aktivní zápis existuje, ale doklad má booked_at NULL
--   amount_mismatch       — total_with_vat dokladu (přepočtený kurzem) se
--                           neshoduje se saldokontním řádkem zápisu
--
-- Idempotence: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS journal_integrity_findings (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id   INT UNSIGNED NOT NULL,
  finding_type  ENUM('orphan_entry','unbalanced_entry','booked_without_entry',
                     'entry_without_booked','amount_mismatch') NOT NULL,
  finding_count INT UNSIGNED NOT NULL DEFAULT 0,
  detail        LONGTEXT NULL COMMENT 'JSON vzorek prvních N nálezů (rozklik/diagnostika)',
  checked_at    DATETIME NOT NULL COMMENT 'Kdy proběhla kontrola (běh cronu)',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_jif_supplier_type (supplier_id, finding_type),
  KEY idx_jif_supplier (supplier_id),
  CONSTRAINT fk_jif_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
