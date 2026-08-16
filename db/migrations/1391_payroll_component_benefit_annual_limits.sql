-- MyÚčto.cz — MZ-08-W08: roční limit osvobození benefitů se u výchozích
-- mzdových složek nikdy nezaložil.
--
-- `PayrollComponentRepository::ensureDefaults()` vkládala výchozí složky bez
-- sloupce `annual_limit_minor`, takže zůstal NULL. `PayrollInputRepository::approve()`
-- přitom roční strop hlídá jen u složky s NENULOVÝM limitem — u výchozích
-- benefitních složek tedy neproběhla žádná kontrola a benefit prošel v jakékoli
-- výši. Nová firma dostane limit rovnou ze zakládání; tahle migrace dorovná
-- firmy, které už v databázi jsou.
--
-- Doplňuje se JEN tam, kde je částka doložená a kde mzdová složka odpovídá právě
-- jednomu zákonnému limitu:
--
--   ZDRAVOTNI_BENEFIT        § 6 odst. 9 písm. d) bod 1 ZDP — nepeněžní zdravotnické
--                            služby a zdravotnické prostředky, ročně do výše průměrné
--                            mzdy za zdaňovací období (§ 21g ZDP); 2026 = 48 967 Kč.
--   REKREACE_VOLNY_CAS       § 6 odst. 9 písm. d) bod 2 ZDP — rekreace, sport, kultura,
--                            tisk, vzdělávací a předškolní zařízení, ročně do výše
--                            poloviny průměrné mzdy; 2026 = 24 483,50 Kč.
--   PRISPEVEK_PENZE_ZIVOTNI  § 6 odst. 9 písm. p) ZDP — příspěvek na daňově podporované
--                            produkty spoření na stáří a na pojištění dlouhodobé péče,
--                            v úhrnu nejvýše 50 000 Kč ročně.
--
-- Zákonný limit je ÚHRN za dané ustanovení, zatímco `annual_limit_minor` je strop
-- JEDNÉ složky. Je to tedy nutná, ne postačující podmínka: složka, která limit sama
-- přeteče, ho přeteče i v úhrnu, ale součet dvou složek téhož ustanovení tenhle
-- sloupec neuhlídá. Ostatní benefitní složky (PRISPEVEK_STRAVOVANI — limit je za
-- směnu, SOUKROME_VOZIDLO — § 6 odst. 6 je ocenění příjmu, VZDELAVANI — § 6 odst. 9
-- písm. a) je bez limitu, PRISPEVEK_DLOUHODOBA_PECE — zařazení určí až účetní)
-- zůstávají vědomě bez limitu.
--
-- Přepisují se jen řádky s NULL: hodnotu vyplněnou uživatelem migrace nesahá.
-- `row_version` se zvyšuje záměrně, aby formulář otevřený před migrací narazil na
-- konflikt verzí místo toho, aby limit tiše přepsal zpátky na NULL.

SET NAMES utf8mb4;

UPDATE payroll_component_definitions
   SET annual_limit_minor = CASE code
           WHEN 'ZDRAVOTNI_BENEFIT' THEN 4896700
           WHEN 'REKREACE_VOLNY_CAS' THEN 2448350
           WHEN 'PRISPEVEK_PENZE_ZIVOTNI' THEN 5000000
       END,
       row_version = row_version + 1
 WHERE annual_limit_minor IS NULL
   AND valid_from = '2026-01-01'
   AND (
        (code = 'ZDRAVOTNI_BENEFIT' AND component_kind = 'benefit_health')
     OR (code = 'REKREACE_VOLNY_CAS' AND component_kind = 'benefit_recreation')
     OR (code = 'PRISPEVEK_PENZE_ZIVOTNI' AND component_kind = 'benefit_pension')
   );
