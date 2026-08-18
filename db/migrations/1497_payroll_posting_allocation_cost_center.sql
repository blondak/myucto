-- MyÚčto.cz — nákladové středisko u cílové účetní alokace mzdové revize.
--
-- `04-UCETNI-MUSTEK.md` slibuje analytiku podle střediska i u zákonných odvodů
-- zaměstnavatele (524/336). Účetní můstek ji doteď neuměl: dimenze uměla přebít
-- ÚČET hrubé mzdy, ale sloupec `journal_entry_lines.cost_center` zůstával
-- u mzdového předpisu prázdný a 524 se účtovalo jednou firemní řádkou.
--
-- Rozdělení nákladu na středisko je ALOKACE firemní částky, ne osobní zákonná
-- částka (§ 5a odst. 1 z. č. 589/1992 Sb. staví základ zaměstnavatele z úhrnu),
-- takže závazek (336) zůstává jednou částkou a dělí se výhradně náklad.
--
-- Sloupec musí být i tady, ne jen v deníku: opravná dávka počítá rozdíl proti
-- ULOŽENÝM alokacím předchozí dávky. Bez uloženého střediska by se zrušená
-- alokace vrátila na řádku bez střediska, a analytika by se rozešla.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS; existující řádky zůstávají NULL, což
-- znamená totéž co dosud — alokace bez střediska.

SET NAMES utf8mb4;

ALTER TABLE payroll_posting_allocations
  ADD COLUMN IF NOT EXISTS cost_center VARCHAR(50) NULL
    COMMENT 'Nákladové středisko alokace (payroll_dimensions.code typu cost_center)';
