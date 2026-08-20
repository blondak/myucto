-- MyÚčto.cz — fáze backfillu „zápočty proti účtu"
--
-- `invoice_settlements.journal_entry_id` má `ON DELETE SET NULL`, takže hromadné
-- přeúčtování deníku vazbu tiše zruší — a protože backfill zápočty neznal, zůstala
-- evidovaná úhrada bez účetního zápisu: doklad tvrdí „uhrazeno", saldokontní účet je
-- otevřený, v detailu chybí proklik a uzávěrková kontrola hlásí díru, kterou uživatel
-- nemá jak zavřít. Doúčtování je nová fáze backfillu a potřebuje vlastní hodnotu
-- v ENUM sloupci `phase` (jinak MariaDB hodnotu utne a job spadne).
--
-- Pozor na jméno: `advance_settlements` je něco jiného — zúčtování ZÁLOH.
--
-- Idempotence: MODIFY na týž tvar sloupce je no-op, migrace jde spustit opakovaně.

SET NAMES utf8mb4;

ALTER TABLE accounting_backfill_jobs
    MODIFY COLUMN phase ENUM('opening','documents','cash','bank','advance_settlements','account_settlements') NULL;
