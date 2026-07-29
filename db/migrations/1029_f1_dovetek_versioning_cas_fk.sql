-- MyÚčto.cz — Issue #15 (F1 dovětek): auditní historie deníku + tvrdá tenant izolace
--
-- Tři aditivní úpravy bez dopadu na chování aplikace:
--   A) SYSTEM VERSIONING na journal_entries + journal_entry_lines — neměnná auditní
--      historie deníku (§35 ZoÚ). Každý přepis řádků (JournalEntryRepository::replace
--      dělá DELETE + re-INSERT při re-postu) zanechá historickou verzi, dotazatelnou
--      přes SELECT ... FOR SYSTEM_TIME. ETag/If-Match (část B) žádnou migraci nepotřebuje
--      — sloupec row_version existuje od 1005.
--   C) Composite FK (supplier_id, account_id) → chart_of_accounts(supplier_id, id) na
--      řádcích deníku: DB odmítne řádek s účtem jiného tenanta (defense-in-depth za
--      PostingService, který dnes hlídá tenant v aplikační vrstvě + testy).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS ekvivalent tu nestačí pro ADD SYSTEM
-- VERSIONING (nemá nativní IF NOT EXISTS a je to MariaDB-only syntaxe). Proto výjimka
-- z pravidla „ne PREPARE/EXECUTE": dynamic SQL tu NENÍ trik na fake-idempotenci, ale
-- jediný způsob, jak (1) skrýt MariaDB-only syntaxi před parserem MySQL při CREATE
-- PROCEDURE a (2) feature-detekovat běhové prostředí. Idempotenci drží CONTINUE HANDLER
-- na chybu 4135 (table is already system-versioned) → opakovaný běh = no-op. Ověřeno na
-- MariaDB 11.8: versioning koexistuje s FK, composite FK odmítá cross-tenant referenci.

SET NAMES utf8mb4;

-- ===== ČÁST A: SYSTEM VERSIONING (auditní historie deníku, MariaDB-only) =====
DELIMITER //

DROP PROCEDURE IF EXISTS sp_f1_add_system_versioning //

CREATE PROCEDURE sp_f1_add_system_versioning()
BEGIN
  -- 4135 = "Table `x` is already system-versioned" → idempotentní no-op při re-běhu.
  DECLARE CONTINUE HANDLER FOR 4135 BEGIN END;
  -- Feature-detekce: na MySQL / non-MariaDB se neprovede nic (aditivní no-op). Dynamic
  -- SQL drží MariaDB-only ADD SYSTEM VERSIONING ve stringu, aby ji parser MySQL při
  -- CREATE PROCEDURE nepřečetl (jinak by celá migrace spadla už na CREATE PROCEDURE).
  IF INSTR(VERSION(), 'MariaDB') > 0 THEN
    SET @sql = 'ALTER TABLE journal_entries ADD SYSTEM VERSIONING';
    PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    SET @sql = 'ALTER TABLE journal_entry_lines ADD SYSTEM VERSIONING';
    PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END //

DELIMITER ;

CALL sp_f1_add_system_versioning();
DROP PROCEDURE IF EXISTS sp_f1_add_system_versioning;

-- ===== ČÁST C: composite FK (supplier_id, account_id) → chart_of_accounts =====
-- Parent klíč: cílové sloupce FK (supplier_id, id) musí být leftmost nějakého indexu.
-- (PK je jen (id), uq_coa_supplier_code je (supplier_id, account_code) — ani jeden
-- nevyhovuje.) id je unikátní, takže (supplier_id, id) je triviálně UNIQUE.
ALTER TABLE chart_of_accounts
  ADD UNIQUE KEY IF NOT EXISTS uq_coa_supplier_id (supplier_id, id);

-- Child: nahraď jednosloupcový fk_jel_account složeným FK. idx_jel_supplier_account
-- (supplier_id, account_id) už existuje = child-side index pro FK.
--
-- POŘADÍ JE ZÁMĚRNÉ (fail-safe): nejdřív přidej NOVÝ FK a starý zahazuj až potom.
-- ADD CONSTRAINT validuje existující řádky (MATCH SIMPLE) — kdyby některý řádek
-- odkazoval účet jiného tenanta (přesně to, co starý jednosloupcový FK dovolil),
-- ADD selže 1452. Díky tomuto pořadí zůstane při selhání starý fk_jel_account
-- zachovaný (tabulka není nikdy bez account FK) a migrace se nezaznamená → po
-- vyčištění dat proběhne znovu. Pre-flight detekce cross-tenant řádků:
--   SELECT COUNT(*) FROM journal_entry_lines jel
--     LEFT JOIN chart_of_accounts c ON c.id = jel.account_id AND c.supplier_id = jel.supplier_id
--    WHERE c.id IS NULL;   -- musí být 0
-- DROP nového jména před ADD drží idempotenci (MariaDB nemá ADD CONSTRAINT IF NOT EXISTS).
ALTER TABLE journal_entry_lines DROP FOREIGN KEY IF EXISTS fk_jel_account_supplier;
ALTER TABLE journal_entry_lines
  ADD CONSTRAINT fk_jel_account_supplier
  FOREIGN KEY (supplier_id, account_id)
  REFERENCES chart_of_accounts (supplier_id, id);
ALTER TABLE journal_entry_lines DROP FOREIGN KEY IF EXISTS fk_jel_account;
-- Osiřelý auto-index (account_id) po starém FK — nový FK jede přes idx_jel_supplier_account.
ALTER TABLE journal_entry_lines DROP INDEX IF EXISTS fk_jel_account;
