-- MyÚčto.cz — příspěvek na stravování (§ 6 odst. 9 písm. b) ZDP) a přechodné
-- ubytování (písm. i)) se konečně dají uplatnit.
--
-- Migrace 1590 obě složky VĚDOMĚ nechala na `manual_review`: „osvobození vázané
-- na limit, který aplikace neumí spočítat …, tady záměrně hodnotu nemá". Důvod
-- byl správný — bez rozpadu by se osvobodila i nadlimitní část — ale následek
-- byl, že na příspěvku na stravování, tedy nejběžnějším benefitu vůbec, padlo
-- schválení každého měsíce, ve kterém ho firma poskytuje.
--
-- ── Co zákon říká (znění účinné pro rok 2026) ─────────────────────────────────
-- písm. b): „příjem zaměstnance ve formě příspěvku na stravování poskytnutého
--   zaměstnavatelem ZA JEDNU SMĚNU podle jiného právního předpisu, pokud během
--   této směny zaměstnanec vykonával práci ALESPOŇ 3 HODINY a nevznikl mu během
--   této směny nárok na stravné v rámci cestovních náhrad …, a to v úhrnu DO
--   VÝŠE 70 % horní hranice stravného, které lze poskytnout zaměstnancům
--   odměňovaným platem při pracovní cestě trvající 5 až 12 hodin, a v úhrnu do
--   výše 70 % této hranice, je-li příspěvek poskytnut jako DALŠÍ PŘÍSPĚVEK
--   v rámci stejné směny, pokud její délka v úhrnu s přestávkou v práci povinně
--   poskytovanou zaměstnavatelem … je DELŠÍ NEŽ 11 HODIN". Pro výkon práce
--   nerozvržený na směny zákon staví tutéž konstrukci na KALENDÁŘNÍM DNI
--   a druhý příspěvek podmiňuje tím, že zaměstnanec „během tohoto dne vykonával
--   práci alespoň 11 hodin".
-- písm. i): „hodnota přechodného ubytování, nejde-li o ubytování při pracovní
--   cestě, poskytovaná jako NEPENĚŽNÍ plnění zaměstnavatelem zaměstnancům
--   v souvislosti s výkonem práce, pokud obec přechodného ubytování není shodná
--   s obcí, kde má zaměstnanec bydliště, a to MAXIMÁLNĚ DO VÝŠE 3 500 KČ
--   MĚSÍČNĚ".
--
-- Nerovnosti limitů jsou NEOSTRÉ („do výše", „maximálně do výše"), takže částka
-- rovná stropu je ještě celá osvobozená. Podmínka odpracované doby je také
-- neostrá („alespoň 3 hodiny"), kdežto délka směny pro druhý příspěvek je OSTRÁ
-- („delší než 11 hodin") — a měří se v úhrnu s přestávkou, tedy hrubý interval
-- směny, ne odpracovaná doba.
--
-- ── Jak se to počítá ──────────────────────────────────────────────────────────
-- Žádný třetí mechanismus nevzniká. Používá se TÝŽ koš jako u § 6 odst. 9
-- písm. d) a m) (migrace 1480): složka nese zařazení do koše, mzdový vstup si
-- při schválení zmrazí rozpad na osvobozenou a nadlimitní část a výpočet běhu
-- z toho udělá samostatnou složku `.nadlimit` se vstupem do daně i do obou
-- vyměřovacích základů. Liší se jen ROZHODNÉ OBDOBÍ úhrnu (kalendářní měsíc
-- místo zdaňovacího období) a u písm. b) i způsob, jak strop vzniká: limit
-- z rulesetu se násobí počtem směn s nárokem, který dodá evidence docházky
-- (MZ-06), ne odhad z úvazku.
--
-- ── Co zůstává fail-closed ────────────────────────────────────────────────────
-- Není-li docházka měsíce uzavřená, počet směn není podklad a schválení vstupu
-- spadne na `meal_shift_evidence_incomplete` s pojmenovaným důvodem. Uzavřená
-- docházka bez jediné odpracované směny naopak podklad JE — znamená nula nároků,
-- tedy celý příspěvek zdanitelný.
--
-- Podmínky, které aplikace v datech nemá (obec bydliště versus obec ubytování,
-- nepeněžní forma plnění u písm. i), povaha stravování u písm. b)), nese ZAŘAZENÍ
-- složky, které volí účetní. Aplikace hlídá to jediné, co spočítat umí.

SET NAMES utf8mb4;

-- 1. Nový druh složky pro přechodné ubytování.
ALTER TABLE payroll_component_definitions
  MODIFY COLUMN component_kind ENUM(
    'base_wage','hourly_wage','task_wage','bonus','premium','commission',
    'allowance','compensation','severance','competitive_clause','backpay',
    'non_cash','benefit_meal',
    'benefit_vehicle','benefit_pension','benefit_care','benefit_education',
    'benefit_recreation','benefit_health','benefit_accommodation','risky_savings',
    'travel_reimbursement','other'
  ) NOT NULL;

-- 2. Dva nové koše a nový podklad osvobození.
ALTER TABLE payroll_component_definitions
  MODIFY COLUMN exemption_basket
    ENUM('non_cash_health','non_cash_leisure','old_age_savings',
         'meal_per_shift','temporary_accommodation') NULL;

ALTER TABLE payroll_component_definitions
  MODIFY COLUMN exemption_basis
    ENUM('not_subject_to_tax','statutory_exempt','benefit_basket',
         'periodic_benefit_limit') NULL;

ALTER TABLE payroll_inputs
  MODIFY COLUMN benefit_basket
    ENUM('non_cash_health','non_cash_leisure','old_age_savings',
         'meal_per_shift','temporary_accommodation') NULL;

-- 3. Podmínku „koš je povinný" splňuje nově i `periodic_benefit_limit`.
--    MariaDB neumí u CHECK `IF NOT EXISTS`, proto se nejdřív zahazuje.
ALTER TABLE payroll_component_definitions
  DROP CONSTRAINT IF EXISTS chk_payroll_component_exemption_basis;
ALTER TABLE payroll_component_definitions
  ADD CONSTRAINT chk_payroll_component_exemption_basis CHECK (
    exemption_basis IS NULL
    OR (tax_treatment = 'exempt'
        AND (exemption_basis NOT IN ('benefit_basket', 'periodic_benefit_limit')
             OR exemption_basket IS NOT NULL))
  );

-- 4. Přeřazení výchozí složky příspěvku na stravování.
--
--    Přepisuje se jen řádek, který je pořád ve stavu, jak ho aplikace založila
--    (`manual_review` napříč všemi zacházeními, bez koše). Vlastní klasifikaci
--    zaměstnavatele migrace nesahá — účetní mohla mít důvod ji změnit.
UPDATE payroll_component_definitions
   SET tax_treatment = 'exempt',
       social_participation_treatment = 'excluded',
       social_treatment = 'excluded',
       health_participation_treatment = 'excluded',
       health_treatment = 'excluded',
       average_earning_treatment = 'excluded',
       enforcement_treatment = 'excluded',
       jmhz_treatment = 'excluded',
       exemption_basket = 'meal_per_shift',
       exemption_basis = 'periodic_benefit_limit',
       row_version = row_version + 1
 WHERE code = 'PRISPEVEK_STRAVOVANI'
   AND component_kind = 'benefit_meal'
   AND exemption_basket IS NULL
   AND exemption_basis IS NULL
   AND tax_treatment = 'manual_review'
   AND social_treatment = 'manual_review'
   AND health_treatment = 'manual_review'
   AND enforcement_treatment = 'manual_review'
   AND jmhz_treatment = 'manual_review';

-- 5. Nová výchozí složka přechodného ubytování pro firmy, které už číselník mají.
--
--    `INSERT ... SELECT` klonuje jen supplier_id a datum účinnosti z existující
--    složky příspěvku na stravování — tím se nová složka založí právě těm
--    firmám, které výchozí číselník opravdu mají, a se stejným `valid_from`.
--    `INSERT IGNORE` drží idempotenci proti `uq_payroll_component_version`.
INSERT IGNORE INTO payroll_component_definitions
    (supplier_id, code, name, component_kind, value_kind, frequency_kind,
     tax_treatment,
     social_participation_treatment, social_treatment,
     health_participation_treatment, health_treatment,
     average_earning_treatment, enforcement_treatment, jmhz_treatment,
     statistics_treatment, exemption_basket, exemption_basis,
     valid_from, valid_to)
SELECT meal.supplier_id, 'PRECHODNE_UBYTOVANI', 'Přechodné ubytování zaměstnance',
       'benefit_accommodation', 'non_monetary', 'regular',
       'exempt',
       'excluded', 'excluded',
       'excluded', 'excluded',
       'excluded', 'excluded', 'excluded',
       'included', 'temporary_accommodation', 'periodic_benefit_limit',
       meal.valid_from, meal.valid_to
  FROM payroll_component_definitions meal
 WHERE meal.code = 'PRISPEVEK_STRAVOVANI';
