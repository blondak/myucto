-- MyÚčto.cz — Epic F1 fix: cizí měna na řádcích deníku + typ závěrkových účtů
-- (nezávislé legislativní review, Fable)
--
-- 1) MĚNA NA ŘÁDKU (§4 odst. 12 ZoÚ): pohledávky, závazky, valuty, ceniny a
--    devizové účty se vedou SOUČASNĚ v cizí měně. Bez uložené měny/kurzu/částky
--    v cizí měně u saldokontních řádků (311/321…) nelze k rozvahovému dni provést
--    kurzové přecenění otevřených položek (§24 odst. 6, uzávěrka krok 2, 563/663).
--    Sloupce jsou NULLABLE — NULL = řádek je v účetní (domácí) měně, není co přeceňovat.
--
-- 2) ZÁVĚRKOVÉ ÚČTY: 701/702/710 byly typované jako 'equity', čímž ve výkazech
--    splývají s vlastním kapitálem (702 během uzávěrky nese zrcadlo všech
--    rozvahových zůstatků). Přidán typ 'closing', aby je generátor rozvahy/VZZ
--    (Epic F2) mohl vyloučit podle TYPU, ne podle prefixu kódu.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS / MODIFY (opakovaně bezpečné).

SET NAMES utf8mb4;

-- 1) cizoměnové sloupce na řádcích deníku
ALTER TABLE journal_entry_lines
  ADD COLUMN IF NOT EXISTS currency_code  CHAR(3)        NULL COMMENT 'Cizí měna řádku (NULL = účetní měna CZK)'      AFTER amount,
  ADD COLUMN IF NOT EXISTS fx_rate        DECIMAL(18,6)  NULL COMMENT 'Kurz k účetní měně (pro přecenění §24/6)'       AFTER currency_code,
  ADD COLUMN IF NOT EXISTS amount_foreign DECIMAL(15,2)  NULL COMMENT 'Částka v cizí měně (§4/12 — souběžné vedení)'   AFTER fx_rate;

-- 2) typ 'closing' pro závěrkové účty (701/702/710); přetypování řídí seed šablony
ALTER TABLE chart_of_accounts
  MODIFY account_type ENUM('asset','liability','equity','revenue','expense','offbalance','closing') NOT NULL;
