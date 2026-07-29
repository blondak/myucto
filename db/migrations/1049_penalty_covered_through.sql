-- MyÚčto.cz — Fáze F: penalizace — ochrana proti dvojí/překrývající se penalizaci
--
-- Penalizační faktura (invoice_type='penalty') si pamatuje POSLEDNÍ DEN prodlení,
-- který pokrývá (accrual "as_of" použité při výpočtu). Když uživatel vytvoří další
-- penalizaci ke stejné zdrojové faktuře (parent_invoice_id), výpočet úroku začne
-- až DEN PO tomto datu — zabrání se tak dvojímu vyúčtování téhož období prodlení
-- (viz InvoiceRepository::lastPenaltyCoveredThrough / PenaltyInvoiceService::preview).
--
-- Zároveň opravuje zbytečně varovný text poznámky u seedu repo sazeb z migrace
-- 1048 ("ověřit dle ČNB") — hodnoty byly nezávisle ověřeny jako správné.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS; UPDATE je bezpečně opakovatelný (mění
-- jen řádky se starým textem poznámky).

SET NAMES utf8mb4;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS penalty_covered_through DATE NULL
    COMMENT 'Jen invoice_type=penalty: poslední den prodlení pokrytý touto penalizační fakturou (accrual as_of) — najde navazující penalizace';

UPDATE cnb_repo_rates
   SET note = 'zdroj: ČNB, seed 2026-07'
 WHERE note LIKE '%ověřit dle ČNB%';
