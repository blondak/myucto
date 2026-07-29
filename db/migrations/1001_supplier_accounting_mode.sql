-- MyÚčto.cz — Epic F0: režim účetnictví per firma (supplier.accounting_mode)
--
-- 'tax_evidence' = daňová evidence (výchozí, dnešní chování MyInvoice)
-- 'double_entry' = podvojné účetnictví (Epic F1+ — účtová osnova, deník, hlavní kniha)
--
-- Sloupec čte/zapisuje SettingsAction (GET/PUT /api/settings/supplier a
-- /api/suppliers/{id}); budoucí účetní moduly podle něj větví chování.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS accounting_mode ENUM('tax_evidence','double_entry') NOT NULL DEFAULT 'tax_evidence'
    COMMENT 'daňová evidence vs podvojné účetnictví (Epic F0/F1)';
