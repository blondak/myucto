-- Kreditní karty: režim účtování nákupů, mezičlen úvěrového účtu, nastavitelné účty
-- a počáteční dluh z prvního výpisu.
--
--   * purchase_mode: 'clearing' = nákup se zaúčtuje hned MD 378.x / D 231.x a vypořádá se
--     dokladem (321/378.x) nebo uzavřením bez dokladu; 'direct' = bez mezičlenu, nákup
--     zaúčtuje párování s dokladem, pravidlo nebo ruční zápis. U účtu NULL = výchozí
--     hodnota firmy z credit_card_settings.
--   * clearing_suffix: analytika mezičlenu úvěrového účtu pod syntetikou mezičlenu karet
--     (378.101 …, stejná řada jako platební karty). Karta se u kreditky pozná podle účtu
--     výpisu, ne podle koncovky - kreditní účet JE karta.
--   * opening_entry_id: zápis počátečního dluhu (jen jednou za účet).
--   * credit_card_settings: účty uzavření bez dokladu (daňový / nedaňový náklad),
--     soukromého nákupu (pohledávka za držitelem) a protiúčet počátečního dluhu.
--     NULL = účet z nastavení platebních karet, jinak výchozí kód.
--
-- Migrace nic nepřeúčtovává a do deníku nesahá.

SET NAMES utf8mb4;

ALTER TABLE credit_card_accounts
  ADD COLUMN IF NOT EXISTS purchase_mode ENUM('clearing','direct') NULL COMMENT 'NULL = výchozí režim firmy' AFTER analytic_suffix,
  ADD COLUMN IF NOT EXISTS clearing_suffix VARCHAR(6) NULL COMMENT 'analytika mezičlenu - kód = <syntetika mezičlenu>.<suffix>' AFTER purchase_mode,
  ADD COLUMN IF NOT EXISTS opening_entry_id BIGINT UNSIGNED NULL COMMENT 'zápis počátečního dluhu z prvního výpisu' AFTER clearing_suffix;

ALTER TABLE credit_card_accounts
  ADD UNIQUE KEY IF NOT EXISTS uq_cca_supplier_clearing (supplier_id, clearing_suffix);

ALTER TABLE credit_card_accounts DROP CONSTRAINT IF EXISTS chk_cca_clearing_suffix;
ALTER TABLE credit_card_accounts
  ADD CONSTRAINT chk_cca_clearing_suffix CHECK (clearing_suffix IS NULL OR clearing_suffix REGEXP '^[0-9]{1,6}$');

ALTER TABLE credit_card_accounts DROP FOREIGN KEY IF EXISTS fk_cca_opening_entry;
ALTER TABLE credit_card_accounts
  ADD CONSTRAINT fk_cca_opening_entry FOREIGN KEY (opening_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL;

ALTER TABLE credit_card_settings
  ADD COLUMN IF NOT EXISTS purchase_mode ENUM('clearing','direct') NOT NULL DEFAULT 'clearing' AFTER supplier_id,
  ADD COLUMN IF NOT EXISTS writeoff_tax_account_id BIGINT UNSIGNED NULL COMMENT 'uzavření bez dokladu, daňový náklad (NULL = 518)' AFTER reward_account_id,
  ADD COLUMN IF NOT EXISTS writeoff_nontax_account_id BIGINT UNSIGNED NULL COMMENT 'uzavření bez dokladu, nedaňový náklad (NULL = nastavení platebních karet)' AFTER writeoff_tax_account_id,
  ADD COLUMN IF NOT EXISTS private_account_id BIGINT UNSIGNED NULL COMMENT 'soukromý nákup k tíži držitele (NULL = nastavení platebních karet, 335)' AFTER writeoff_nontax_account_id,
  ADD COLUMN IF NOT EXISTS opening_account_id BIGINT UNSIGNED NULL COMMENT 'protiúčet počátečního dluhu (NULL = 379)' AFTER private_account_id;

ALTER TABLE credit_card_settings
  DROP FOREIGN KEY IF EXISTS fk_ccst_writeoff_tax,
  DROP FOREIGN KEY IF EXISTS fk_ccst_writeoff_nontax,
  DROP FOREIGN KEY IF EXISTS fk_ccst_private,
  DROP FOREIGN KEY IF EXISTS fk_ccst_opening;

ALTER TABLE credit_card_settings
  ADD CONSTRAINT fk_ccst_writeoff_tax FOREIGN KEY (writeoff_tax_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_ccst_writeoff_nontax FOREIGN KEY (writeoff_nontax_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_ccst_private FOREIGN KEY (private_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_ccst_opening FOREIGN KEY (opening_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL;
