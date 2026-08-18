-- MyUcto.cz - OZUSPOJ: evidence záměru uplatňovat slevu na pojistném (§ 23e).
--
-- Sleva podle § 7a zákona č. 589/1992 Sb. se sice vykazuje v JMHZ (§ 7c odst. 1),
-- ale nárok na ni stojí na ÚPLNĚ JINÉM podání. § 7a odst. 5: „Sleva na pojistném
-- za zaměstnance náleží zaměstnavateli, jen pokud nejpozději s uplatněním této
-- slevy oznámil České správě sociálního zabezpečení záměr uplatňovat tuto slevu
-- za tohoto zaměstnance; oznámením tohoto záměru se rozumí okamžik jeho doručení
-- České správě sociálního zabezpečení."
--
-- § 23e odst. 1 a 2 pro to předepisuje samostatný tiskopis — e-podání OZUSPOJ —
-- a ČSSZ ho vede v evidenci podle § 23f odst. 2 a 3 (systém ZAMERY_SLEV). Do
-- 17. 8. 2026 znal modul jen ručně opsané datum ve sloupci
-- `payroll_employment_terms.social_part_time_discount_notified_on`, kterému
-- nešlo věřit: nebylo z čeho odvodit, kdy záměr začal platit, jestli dosud trvá
-- a jestli ho ČSSZ vůbec přijala.
--
-- Tabulka drží ty tři údaje odděleně, protože každý hraje v kontrole 291
-- katalogu kontrol MH jinou roli:
--   * `intent_from` / `intent_to` = ZAMERY_SLEV.ZAMER_OD / ZAMER_DO, tedy období,
--     na které je záměr oznámen. Kontrola 291 bod 1 žádá, aby do něj spadalo
--     trvání zaměstnání, ze kterého se sleva uplatňuje.
--   * `accepted_on` = ZAMERY_SLEV.DATUM_PRIJETI_FORMULARE, tedy den DORUČENÍ
--     ČSSZ. Kontrola 291 bod 2 porovnává právě jeho, ne den, od kdy záměr platí.
--   * `status` odděluje „vyplněno v aplikaci" od „ČSSZ přijala". Sleva se smí
--     uplatnit jen podle přijatého záměru; § 7c odst. 3 dělá z přeplacené slevy
--     dluh na pojistném, kdežto z neuplatněné žádný nedoplatek nevzniká, takže
--     každý neznámý stav musí končit NEUPLATNĚNÍM.
--
-- `employee_informed_on` není administrativa navíc: § 23d odst. 2 ukládá
-- zaměstnavateli písemně informovat zaměstnance o oznámeném záměru PŘED prvním
-- uplatněním slevy a § 25a odst. 2 písm. j) z toho dělá přestupek. Bez sloupce
-- by povinnost neexistovala pro nikoho.
--
-- Souběh u více zaměstnavatelů (§ 7a odst. 5 věta třetí) rozhoduje ČSSZ podle
-- pořadí doručení — aplikace ho odhadovat NESMÍ. Proto stav `rejected`
-- s důvodem: teprve odmítnutí od ČSSZ je informace o tom, že byl někdo první.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_discount_intents (
  id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id               INT UNSIGNED NOT NULL,
  environment               ENUM('production','test') NOT NULL,
  employee_id               BIGINT UNSIGNED NOT NULL,
  employment_id             BIGINT UNSIGNED NOT NULL,
  discount_reason           ENUM(
                              'age_55_plus',
                              'child_care_under_10',
                              'dependent_close_person_care',
                              'study_under_26',
                              'retraining_jobseeker',
                              'disabled_person',
                              'under_21'
                            ) NOT NULL,
  intent_from               DATE NOT NULL,
  intent_to                 DATE NULL,
  status                    ENUM(
                              'draft',
                              'submitted',
                              'accepted',
                              'rejected',
                              'ended',
                              'cancelled'
                            ) NOT NULL DEFAULT 'draft',
  accepted_on               DATE NULL,
  ended_accepted_on         DATE NULL,
  rejection_reason          VARCHAR(190) NULL,
  employee_informed_on      DATE NULL,
  ossz_code                 SMALLINT UNSIGNED NOT NULL,
  start_submission_id       BIGINT UNSIGNED NULL,
  end_submission_id         BIGINT UNSIGNED NULL,
  row_version               INT UNSIGNED NOT NULL DEFAULT 1,
  created_by                BIGINT UNSIGNED NOT NULL,
  created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_discount_intent_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_discount_intent_environment_id
    (supplier_id, environment, id),
  UNIQUE KEY uq_payroll_discount_intent_scope
    (supplier_id, environment, employment_id, intent_from),
  KEY idx_payroll_discount_intent_employee
    (supplier_id, environment, employee_id, intent_from),
  KEY idx_payroll_discount_intent_status
    (supplier_id, environment, status, intent_from),

  CONSTRAINT fk_payroll_discount_intent_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_discount_intent_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_discount_intent_start_submission
    FOREIGN KEY (supplier_id, environment, start_submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_discount_intent_end_submission
    FOREIGN KEY (supplier_id, environment, end_submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_discount_intent_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MariaDB neumí IF NOT EXISTS u CHECK, takže se každé omezení nejdřív zahodí.

ALTER TABLE payroll_discount_intents
  DROP CONSTRAINT IF EXISTS chk_payroll_discount_intent_period;
ALTER TABLE payroll_discount_intents
  ADD CONSTRAINT chk_payroll_discount_intent_period
    CHECK (intent_to IS NULL OR intent_to >= intent_from);

-- Přijatý ani ukončený záměr nesmí existovat bez data doručení ČSSZ. Kdyby
-- směl, spadl by výpočet slevy zpátky na „nevím kdy" a kontrola 291 bod 2 by
-- neměla co porovnat.
ALTER TABLE payroll_discount_intents
  DROP CONSTRAINT IF EXISTS chk_payroll_discount_intent_accepted;
ALTER TABLE payroll_discount_intents
  ADD CONSTRAINT chk_payroll_discount_intent_accepted
    CHECK (
      (status IN ('accepted','ended') AND accepted_on IS NOT NULL)
      OR (status NOT IN ('accepted','ended') AND accepted_on IS NULL)
    );

-- Ukončení záměru (§ 23e odst. 2) je vlastní podání s vlastním dnem doručení.
ALTER TABLE payroll_discount_intents
  DROP CONSTRAINT IF EXISTS chk_payroll_discount_intent_ended;
ALTER TABLE payroll_discount_intents
  ADD CONSTRAINT chk_payroll_discount_intent_ended
    CHECK (
      (status = 'ended' AND intent_to IS NOT NULL
        AND ended_accepted_on IS NOT NULL)
      OR (status <> 'ended' AND ended_accepted_on IS NULL)
    );

ALTER TABLE payroll_discount_intents
  DROP CONSTRAINT IF EXISTS chk_payroll_discount_intent_rejected;
ALTER TABLE payroll_discount_intents
  ADD CONSTRAINT chk_payroll_discount_intent_rejected
    CHECK (
      (status = 'rejected' AND rejection_reason IS NOT NULL)
      OR (status <> 'rejected' AND rejection_reason IS NULL)
    );

-- Kód OSSZ je tříciferný podle číselníku CIS_PRACOVIST, jak ho žádá
-- `zamer/kodOSSZ` v OZUSPOJ23.xsd (minInclusive 100, maxInclusive 999).
ALTER TABLE payroll_discount_intents
  DROP CONSTRAINT IF EXISTS chk_payroll_discount_intent_ossz;
ALTER TABLE payroll_discount_intents
  ADD CONSTRAINT chk_payroll_discount_intent_ossz
    CHECK (ossz_code BETWEEN 100 AND 999);
