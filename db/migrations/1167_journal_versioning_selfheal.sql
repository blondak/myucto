-- 1167: § 12 ZoÚ — doplnit SYSTEM VERSIONING na deníku, pokud chybí (self-heal k 1029)
--
-- Migrace 1029 versioning zapíná, ale invariant I29 ho na produkčních datech NENAŠEL:
-- `migrations` obsahuje 1029 jako proběhlou a přesto ani journal_entries, ani
-- journal_entry_lines versioning nemají.
--
-- ── Proč se to stane a proč to testy nechytí ────────────────────────────────────────
-- Typicky importem dumpu: záznam v `migrations` se přenese spolu s daty, ale tabulky
-- v dumpu vzniknou přesně tak, jak byly zdrojově vytvořené — tedy bez versioningu.
-- Migrátor pak 1029 přeskočí („už proběhla") a nikdo se nedozví, že výsledek chybí.
-- Testovací databáze versioning MÁ, takže sada je zelená a rozdíl se projeví jen tam,
-- kde na něm záleží — na ostrých datech.
--
-- Následek je tichý, ne hlučný: `JournalHistoryService` na neverzované tabulce nespadne,
-- `FOR SYSTEM_TIME ALL` prostě vrátí aktuální řádek. Historie zápisu tedy nevypadá
-- rozbitě, vypadá PRÁZDNĚ — a přepis zápisu při re-postu po sobě nenechá stopu, přestože
-- § 12 ZoÚ průkaznost účetních záznamů vyžaduje.
--
-- Tahle migrace proto stav OVĚŘÍ a doplní, místo aby se spoléhala na to, že 1029 doběhla.
-- Je bezpečné ji pouštět opakovaně: na už verzované tabulce chybu 4135 spolkne handler.
--
-- ── system_versioning_alter_history ─────────────────────────────────────────────────
-- Bez `SET @@system_versioning_alter_history = 1` odmítne MariaDB jakýkoli pozdější
-- ALTER verzované tabulky (chyba 4119) — a to by zablokovalo VŠECHNY další migrace nad
-- deníkem. Nastavuje se jen pro tuhle session, globální stav se nemění.

SET NAMES utf8mb4;
SET @@system_versioning_alter_history = 1;

DELIMITER //

DROP PROCEDURE IF EXISTS sp_journal_versioning_selfheal //

CREATE PROCEDURE sp_journal_versioning_selfheal()
BEGIN
  -- 4135 = „Table is already system-versioned" → opakovaný běh je no-op.
  DECLARE CONTINUE HANDLER FOR 4135 BEGIN END;

  -- Dynamic SQL: ADD SYSTEM VERSIONING je MariaDB-only syntaxe a na MySQL by shodila
  -- už CREATE PROCEDURE, kdyby byla v těle přímo. Feature-detekce stejná jako v 1029.
  IF INSTR(VERSION(), 'MariaDB') > 0 THEN
    IF NOT EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_entries'
         AND GENERATION_EXPRESSION LIKE '%ROW START%'
    ) THEN
      SET @sql = 'ALTER TABLE journal_entries ADD SYSTEM VERSIONING';
      PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;

    IF NOT EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_entry_lines'
         AND GENERATION_EXPRESSION LIKE '%ROW START%'
    ) THEN
      SET @sql = 'ALTER TABLE journal_entry_lines ADD SYSTEM VERSIONING';
      PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
  END IF;
END //

DELIMITER ;

CALL sp_journal_versioning_selfheal();
DROP PROCEDURE IF EXISTS sp_journal_versioning_selfheal;
