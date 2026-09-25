-- Platby kartou se už neúčtují přes mezičlen (378.x); platba kartou z výpisu se účtuje
-- přímo jako každá jiná bankovní platba (321/221, u kreditní karty 321/231.x).
--
-- Migrace maže jen sloupce, které patřily výhradně mezičlenu a které převod dřívějších
-- zápisů nepotřebuje: režim nákupů kreditkou, účty uzavření nákupu bez dokladu a příznak
-- karty založené importem. Deník nemění.
--
-- Záměrně ZŮSTÁVÁ (převod api/bin/card-clearing-to-direct.php, auto-backfill v migrate.php,
-- z nich pozná analytiky karet a běží až PO migracích):
--   * tabulka card_clearing_settings,
--   * payment_cards.analytic_suffix, credit_card_accounts.clearing_suffix,
--   * hodnoty card_settlement a card_writeoff v journal_entries.source_type (zápisy
--     v uzavřených obdobích zůstávají, jak byly).
-- Aplikace je nečte ani nezapisuje; odstraní je pozdější migrace.

SET NAMES utf8mb4;

ALTER TABLE credit_card_settings
  DROP FOREIGN KEY IF EXISTS fk_ccst_writeoff_tax,
  DROP FOREIGN KEY IF EXISTS fk_ccst_writeoff_nontax,
  DROP FOREIGN KEY IF EXISTS fk_ccst_private;

ALTER TABLE credit_card_settings
  DROP COLUMN IF EXISTS purchase_mode,
  DROP COLUMN IF EXISTS writeoff_tax_account_id,
  DROP COLUMN IF EXISTS writeoff_nontax_account_id,
  DROP COLUMN IF EXISTS private_account_id;

ALTER TABLE credit_card_accounts
  DROP COLUMN IF EXISTS purchase_mode;

ALTER TABLE payment_cards
  DROP COLUMN IF EXISTS is_verified;
