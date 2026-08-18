-- MyÚčto.cz — MZ-08: roční limit nepeněžních benefitů je SPOLEČNÝ KOŠ za ustanovení
-- § 6 odst. 9 ZDP, ne strop jedné mzdové složky.
--
-- Migrace 1391 dosypala `payroll_component_definitions.annual_limit_minor`
-- zákonnými částkami. Ta úvaha byla v hlavičce 1391 sama označená za nutnou, ne
-- postačující podmínku: sloupec je strop JEDNÉ složky, kdežto zákon limituje ÚHRN
-- plnění za dané ustanovení. Dvě různé složky téhož bodu (třeba „Rekreace" a
-- vlastní složka „Permanentka do bazénu") tedy limit obešly.
--
-- Horší než to: strop na složce se choval jako TVRDÁ ZÁBRANA — schválení vstupu
-- spadlo na `benefit_limit_exceeded`. To zákon neříká. Osvobozeno je plnění
-- „v úhrnu do výše" limitu; co limit převyšuje, je běžný zdanitelný příjem ze
-- závislé činnosti a vstupuje do vyměřovacích základů pojistného. Zaměstnavatel
-- smí benefit poskytnout i nad limit, jen se z přebytku odvádí.
--
-- Proto:
--   1. složka nese `exemption_basket` = ke kterému zákonnému koši patří,
--   2. zákonné částky ze složkového stropu MIZÍ (patří rulesetu a koši);
--      `annual_limit_minor` zůstává jako VLASTNÍ strop zaměstnavatele, který dál
--      blokuje schválení — to je vnitřní pravidlo firmy, ne daňová hranice,
--   3. mzdový vstup si při schválení zmrazí rozpad na osvobozenou a nadlimitní
--      část, aby výpočet běhu nemusel dopočítávat historii.
--
-- Rozpad se zmrazuje, ne dopočítává: pořadí čerpání koše je dané pořadím
-- schválení a přepočet zpětně by u dřívějšího vstupu změnil daňový dopad, který
-- už je v uzavřené revizi mzdového běhu.

SET NAMES utf8mb4;

ALTER TABLE payroll_component_definitions
  ADD COLUMN IF NOT EXISTS exemption_basket
    ENUM('non_cash_health','non_cash_leisure','old_age_savings') NULL
    AFTER annual_limit_minor;

ALTER TABLE payroll_component_definitions
  ADD INDEX IF NOT EXISTS idx_payroll_component_basket
    (supplier_id, exemption_basket);

ALTER TABLE payroll_inputs
  ADD COLUMN IF NOT EXISTS benefit_basket
    ENUM('non_cash_health','non_cash_leisure','old_age_savings') NULL,
  ADD COLUMN IF NOT EXISTS benefit_exempt_minor BIGINT NULL,
  ADD COLUMN IF NOT EXISTS benefit_taxable_minor BIGINT NULL;

-- MariaDB neumí u CHECK `IF NOT EXISTS`, proto se nejdřív zahazuje.
ALTER TABLE payroll_inputs DROP CONSTRAINT IF EXISTS chk_payroll_input_benefit_split;
ALTER TABLE payroll_inputs
  ADD CONSTRAINT chk_payroll_input_benefit_split CHECK (
    (benefit_basket IS NULL
      AND benefit_exempt_minor IS NULL
      AND benefit_taxable_minor IS NULL)
    OR
    (benefit_basket IS NOT NULL
      AND benefit_exempt_minor IS NOT NULL AND benefit_exempt_minor >= 0
      AND benefit_taxable_minor IS NOT NULL AND benefit_taxable_minor >= 0)
  );

-- Zařazení výchozích složek do zákonných košů. Přepisuje se jen NULL, hodnotu
-- zadanou uživatelem migrace nesahá.
UPDATE payroll_component_definitions
   SET exemption_basket = CASE code
           WHEN 'ZDRAVOTNI_BENEFIT' THEN 'non_cash_health'
           WHEN 'REKREACE_VOLNY_CAS' THEN 'non_cash_leisure'
           WHEN 'PRISPEVEK_PENZE_ZIVOTNI' THEN 'old_age_savings'
       END,
       row_version = row_version + 1
 WHERE exemption_basket IS NULL
   AND (
        (code = 'ZDRAVOTNI_BENEFIT' AND component_kind = 'benefit_health')
     OR (code = 'REKREACE_VOLNY_CAS' AND component_kind = 'benefit_recreation')
     OR (code = 'PRISPEVEK_PENZE_ZIVOTNI' AND component_kind = 'benefit_pension')
   );

-- Zákonná částka ze složkového stropu pryč. Ruší se jen tam, kde se rovná přesně
-- částce, kterou tam dosadila migrace 1391 — vlastní číslo zaměstnavatele
-- (i kdyby bylo jen o korunu jiné) zůstává jako jeho vnitřní strop.
UPDATE payroll_component_definitions
   SET annual_limit_minor = NULL,
       row_version = row_version + 1
 WHERE exemption_basket IS NOT NULL
   AND annual_limit_minor IS NOT NULL
   AND (
        (code = 'ZDRAVOTNI_BENEFIT' AND annual_limit_minor = 4896700)
     OR (code = 'REKREACE_VOLNY_CAS' AND annual_limit_minor = 2448350)
     OR (code = 'PRISPEVEK_PENZE_ZIVOTNI' AND annual_limit_minor = 5000000)
   );
