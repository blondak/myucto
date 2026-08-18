-- MyÚčto.cz — sleva zaměstnavatele na pojistném za kratší úvazky (§ 7a ZPSZ).
--
-- `SocialInsuranceRelationshipInput::$partTimeEmployerDiscount` existoval od
-- začátku, kalkulátor slevu uměl spočítat i vykázat v JMHZ — jenže mzdový běh
-- ho nikdy nenastavil. Uživatel neměl kde nárok zadat, takže se sleva podle
-- § 7a nemohla uplatnit vůbec a zaměstnavatel platil o 5 % vyměřovacího
-- základu víc, než musel.
--
-- § 7a odst. 1 vyjmenovává důvody nároku UZAVŘENÝM výčtem písmen a) až g),
-- proto ENUM a ne volný text: na písmenu závisí, jestli se posuzuje podmínka
-- kratší pracovní doby podle odst. 2 a hodinový limit podle odst. 3 písm. c)
-- (obojí platí jen pro a) až f), nikoli pro zaměstnance mladšího 21 let).
--
-- § 7a odst. 5: sleva náleží, JEN pokud zaměstnavatel nejpozději s jejím
-- uplatněním oznámil České správě sociálního zabezpečení záměr ji za tohoto
-- zaměstnance uplatňovat. Datum oznámení je proto podmínka nároku, ne poznámka
-- — bez něj se sleva neuplatní. Odkaz na podklad drží doložení skutečnosti
-- podle odst. 1 (věk, péče, studium, rekvalifikace, zdravotní postižení).
--
-- Sleva je výhoda ZAMĚSTNAVATELE a § 7c odst. 3 dělá z přeplacené slevy dluh
-- na pojistném, kdežto neuplatněná sleva žádný nedoplatek nezakládá. Výchozí
-- stav je proto `none` a chybějící údaj vede na neuplatnění, nikdy naopak.

SET NAMES utf8mb4;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS social_part_time_discount_reason
    ENUM(
      'none',
      'age_55_plus',
      'child_care_under_10',
      'dependent_close_person_care',
      'study_under_26',
      'retraining_jobseeker',
      'disabled_person',
      'under_21'
    ) NOT NULL DEFAULT 'none'
    AFTER social_employer_rate_category_evidence;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS social_part_time_discount_evidence VARCHAR(190) NULL
    AFTER social_part_time_discount_reason;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS social_part_time_discount_notified_on DATE NULL
    AFTER social_part_time_discount_evidence;

-- MariaDB neumí IF NOT EXISTS u CHECK, takže se nejdřív zahazuje.
ALTER TABLE payroll_employment_terms
  DROP CONSTRAINT IF EXISTS chk_payroll_employment_term_part_time_discount;

ALTER TABLE payroll_employment_terms
  ADD CONSTRAINT chk_payroll_employment_term_part_time_discount CHECK (
    social_part_time_discount_reason = 'none'
      AND social_part_time_discount_evidence IS NULL
      AND social_part_time_discount_notified_on IS NULL
    OR social_part_time_discount_reason <> 'none'
  );
