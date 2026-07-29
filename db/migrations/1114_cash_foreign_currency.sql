-- MyÚčto.cz — §11: valutová (cizoměnová) pokladna.
--
-- Pokladna může být vedená v cizí měně (EUR, USD…). Doklady takové pokladny nesou
-- souběžně částku v cizí měně (amount_foreign) i CZK ekvivalent (total_amount = kurz
-- ČNB × amount_foreign, §4/12 ZoÚ). CZK zůstává default; stávající pokladny i doklady
-- se NEHNOU (currency_code='CZK', fx_rate=1, amount_foreign NULL).
--
-- Analytika per pokladna: každá valutová pokladna má vlastní analytiku 211<suffix>
-- (nosič CZK zůstatku i cizoměnové stopy). 211<suffix> line pokladního zápisu nese
-- currency_code/fx_rate/amount_foreign → ClosingRepository::bankProposals ji vidí a
-- FxRevaluationService ji k rozvahovému dni přecení AUTOMATICKY (563/663), stejně
-- jako valutový bankovní účet (migrace 1109).
--
-- Idempotence: ADD COLUMN IF NOT EXISTS + MODIFY (jen komentář/default, bez ztráty dat).

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- 1) Cizoměnová částka dokladu (NULL = CZK doklad; total_amount je pak přímo částka).
ALTER TABLE cash_documents
  ADD COLUMN IF NOT EXISTS amount_foreign DECIMAL(15,2) NULL
      COMMENT 'částka v cizí měně (currency_code); total_amount = CZK ekvivalent (kurz ČNB). NULL u CZK dokladů'
      AFTER fx_rate;

-- 2) Upřesnění sémantiky sloupců (jen komentář/default — data beze změny).
ALTER TABLE cash_documents
  MODIFY COLUMN currency_code CHAR(3) NOT NULL DEFAULT 'CZK'
      COMMENT 'měna dokladu = měna pokladny (CZK nebo valuta)',
  MODIFY COLUMN fx_rate DECIMAL(12,6) NOT NULL DEFAULT 1
      COMMENT 'kurz ČNB měny k datu (CZK za 1 jednotku); 1 pro CZK';

ALTER TABLE cash_registers
  MODIFY COLUMN currency_code CHAR(3) NOT NULL DEFAULT 'CZK'
      COMMENT 'měna pokladny (CZK nebo valuta EUR/USD…) — určuje měnu dokladů a analytiky';

-- 3) Pojistka: prázdná/NULL měna existujících pokladen = CZK (default už je 'CZK').
UPDATE cash_registers SET currency_code = 'CZK'
 WHERE currency_code IS NULL OR currency_code = '';
