-- MyÚčto.cz — vrácení sloupců účtování plateb kartou přes mezičlen.
--
-- Zrušená migrace 1897 (odstranění mezičlenu) stihla proběhnout jen na jediné instalaci
-- a smazala nastavení mezičlenu. Mezičlen zůstává volbou firmy, výchozí stav je vypnuto.
-- Na instalaci, kde 1897 neproběhla, migrace nic nemění kromě výchozího režimu nákupů
-- kreditní karty pro nově zakládané nastavení (napřímo); uložené nastavení firem zůstává.

SET NAMES utf8mb4;

ALTER TABLE payment_cards
  ADD COLUMN IF NOT EXISTS is_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;

ALTER TABLE credit_card_accounts
  ADD COLUMN IF NOT EXISTS purchase_mode ENUM('clearing','direct') NULL COMMENT 'NULL = výchozí režim firmy' AFTER analytic_suffix;

ALTER TABLE credit_card_settings
  ADD COLUMN IF NOT EXISTS purchase_mode ENUM('clearing','direct') NOT NULL DEFAULT 'direct' AFTER supplier_id,
  ADD COLUMN IF NOT EXISTS writeoff_tax_account_id BIGINT UNSIGNED NULL COMMENT 'uzavření bez dokladu, daňový náklad (NULL = 518)' AFTER reward_account_id,
  ADD COLUMN IF NOT EXISTS writeoff_nontax_account_id BIGINT UNSIGNED NULL COMMENT 'uzavření bez dokladu, nedaňový náklad (NULL = nastavení platebních karet)' AFTER writeoff_tax_account_id,
  ADD COLUMN IF NOT EXISTS private_account_id BIGINT UNSIGNED NULL COMMENT 'soukromý nákup k tíži držitele (NULL = nastavení platebních karet, 335)' AFTER writeoff_nontax_account_id;

ALTER TABLE credit_card_settings
  ALTER COLUMN purchase_mode SET DEFAULT 'direct';

ALTER TABLE credit_card_settings
  DROP FOREIGN KEY IF EXISTS fk_ccst_writeoff_tax,
  DROP FOREIGN KEY IF EXISTS fk_ccst_writeoff_nontax,
  DROP FOREIGN KEY IF EXISTS fk_ccst_private;

ALTER TABLE credit_card_settings
  ADD CONSTRAINT fk_ccst_writeoff_tax FOREIGN KEY (writeoff_tax_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_ccst_writeoff_nontax FOREIGN KEY (writeoff_nontax_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_ccst_private FOREIGN KEY (private_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL;
