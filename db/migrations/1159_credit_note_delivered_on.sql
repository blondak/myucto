-- 1159: § 42 odst. 3 ZDPH — období opravy základu daně určuje DORUČENÍ opravného dokladu
--
-- Dobropis se zakládal s `tax_date = CURDATE()`, tedy datem VYTVOŘENÍ. Období opravy se
-- ale řídí dnem, kdy byl opravný daňový doklad DORUČEN odběrateli — a ten se od vytvoření
-- běžně liší, typicky přes přelom měsíce. Oprava pak spadla do nesprávného zdaňovacího
-- období a jedinou pojistkou bylo neblokující varování.
--
-- `effective_tax_date` je generovaný sloupec `COALESCE(tax_date, issue_date)`, takže
-- období řídí právě `tax_date`. Datum doručení se proto eviduje samostatně a při vystavení
-- se z něj `tax_date` odvodí — samotné přepsání `tax_date` by informaci o doručení
-- neuchovalo a nešlo by zpětně doložit, proč doklad spadl do daného období.
--
-- Zrcadlí to řešení § 46 (migrace 1150), kde `delivered_on` už tuhle roli plní.

SET NAMES utf8mb4;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS corrective_delivered_on DATE NULL
        COMMENT 'den doručení opravného daňového dokladu odběrateli (§ 42/3) — určuje období opravy'
        AFTER tax_date;
