-- Sjednocení typu časových sloupců v `bank_posting_suggestions`.
--
-- `created_at` je TIMESTAMP, `reviewed_at` byl DATETIME. Rozdíl není kosmetický:
-- TIMESTAMP se při čtení PŘEVÁDÍ podle zóny session, DATETIME se vrací tak, jak
-- byl uložen. AutomationFeedService přitom oba sloupce míchá v jednom filtru
-- (`DATE(COALESCE(reviewed_at, created_at))`), takže se v témže WHERE potkávaly
-- dvě různě vyložené hodnoty a filtr „od–do" na hranici dne zařadil záznam do
-- jiného dne podle toho, KTERÝ ze dvou sloupců byl vyplněný.
--
-- ⚠️ Převod DATETIME → TIMESTAMP vykládá uložené hodnoty v zóně session, ve které
-- migrace běží. Od opravy zón (Config::load nastavuje `app.timezone` dřív, než
-- vznikne spojení) je to zóna aplikace, takže se řádky zapsané z webu převedou
-- správně. Řádky, které kdysi zapsal cron běžící v UTC (status `auto_posted`),
-- se posunou o offset zóny — jde o historické razítko revize, ne o účetní datum,
-- a rozlišit je zpětně nelze: v DATETIME sloupci není po zóně žádná stopa.
--
-- Idempotence: opakované MODIFY na týž typ je no-op, hodnoty se převádějí jen
-- při skutečné změně typu z DATETIME.

ALTER TABLE bank_posting_suggestions
    MODIFY COLUMN reviewed_at TIMESTAMP NULL DEFAULT NULL;
