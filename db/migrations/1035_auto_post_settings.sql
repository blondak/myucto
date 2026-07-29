-- MyÚčto.cz — Fáze A/A2: per-firma auto-post hook (audit 2026-07)
--
-- Dva opt-in příznaky na firmě: po vystavení vydané faktury (draft→issued), resp.
-- po přijetí přijaté faktury (draft→received) se doklad automaticky zaúčtuje do
-- deníku přes PostingService. Chyba zaúčtování NIKDY nezablokuje vystavení/přijetí
-- (jen audit warning `accounting.auto_post_failed`) — doklad zůstane nezaúčtovaný
-- a uživatel ho dožene ručně nebo hromadně ze seznamu.
--
-- Účinek jen v režimu podvojného účetnictví (accounting_mode = 'double_entry');
-- v daňové evidenci se doklady do deníku neúčtují, takže flag je no-op.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS (vzor stock_enabled, migrace 1023).

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS auto_post_invoices  TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'auto-zaúčtování vydané faktury po vystavení (jen double_entry)',
  ADD COLUMN IF NOT EXISTS auto_post_purchases TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'auto-zaúčtování přijaté faktury po přijetí (jen double_entry)';
