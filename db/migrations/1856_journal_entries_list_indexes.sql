-- MyÚčto.cz — indexy pro výpis účetního deníku.
--
-- PROČ
--
-- Na produkční instalaci s 8 819 zápisy trvalo otevření `/accounting/journal`
-- 10,6 sekundy na jeden dotaz (naměřeno ze slow logu, `Rows_examined: 69786`).
-- Za to mohly dvě věci, obě řešitelné indexem:
--
-- 1. Self-join `rev_src` (dohledání protizápisu, aby ze storna vedl proklik na
--    prvotní doklad) měl podmínku `rev_src.supplier_id = je.supplier_id AND
--    rev_src.reversed_by = je.id`. Na `reversed_by` existoval jen jednosloupcový
--    `fk_je_reversed_by` bez `supplier_id`, takže si ho optimalizátor nevzal a jel
--    BNL scan přes celou tabulku pro každý řádek stránky:
--        rev_src  ALL  key: NULL  Using join buffer (flat, BNL join)  r_filtered: 0.01
--
-- 2. `ORDER BY je.entry_date DESC, je.id DESC` neměl index vůbec — na `entry_date`
--    žádný nebyl. Řazení tedy proběhlo přes `Using temporary; Using filesort`, a
--    protože zápis nese TEXT sloupce, spadla dočasná tabulka na disk.
--
-- CO SE ZAVÁDÍ
--
-- `idx_je_supplier_entry_date (supplier_id, entry_date, id)` — pokrývá zároveň
-- filtr na firmu i řazení deníku, takže plán skončí na `ref … Using index` bez
-- filesortu. Pořadí sloupců odpovídá pořadí v ORDER BY; `id` je na konci kvůli
-- deterministickému rozpadu shodných dat (viz `ORDER BY entry_date, id`).
--
-- `idx_je_supplier_reversed_by (supplier_id, reversed_by)` — dvousloupcová verze
-- stávajícího `fk_je_reversed_by`. Původní index nechávám: visí na něm cizí klíč.
--
-- Naměřeno na kopii produkčních dat (MariaDB 11.8, stejný stroj):
--   self-join + řazení bez indexů   3 401,9 ms
--   self-join + řazení s indexy         0,4 ms
--
-- Index je jen čtecí optimalizace, chování aplikace ani obsah dat nemění.
-- Aditivní a idempotentní (ADD INDEX IF NOT EXISTS — MariaDB 10.6+/11.8 native).

SET NAMES utf8mb4;

ALTER TABLE journal_entries
  ADD INDEX IF NOT EXISTS idx_je_supplier_entry_date (supplier_id, entry_date, id),
  ADD INDEX IF NOT EXISTS idx_je_supplier_reversed_by (supplier_id, reversed_by);
