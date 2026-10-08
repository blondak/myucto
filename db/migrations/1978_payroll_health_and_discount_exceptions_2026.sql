-- MyÚčto.cz — výjimky z účasti a minima ZP od 1. 1. 2026 a sleva při částečné práci.
--
--   * `payroll_person_health_minimum_reductions.reason` + `child_under_7_care`:
--     zaměstnanec pečující o dítě do 7 let je od 1. 1. 2026 státním pojištěncem
--     (§ 7 odst. 1 písm. k) z. 48/1997 Sb. ve znění z. 289/2025 Sb.), minimum
--     se na něj ode dne potvrzeného pojišťovnou nevztahuje (§ 3 odst. 8 a 9
--     z. 592/1992 Sb.).
--   * `payroll_employment_terms.health_association_member`: člen družstva nebo
--     společenství vlastníků jednotek, který pro ně pracuje za odměnu, není
--     zaměstnancem pro ZP v měsíci bez započitatelného příjmu (§ 5 písm. a)
--     body 4 a 5 z. 48/1997 Sb.); nezapočítá se do počtu ani základu PPZ.
--   * `payroll_absences.obstacle_kind` + `partial_work`: částečná práce
--     s příspěvkem (§ 120a a násl. zákona o zaměstnanosti). Zaměstnanec
--     uvedený v měsíčním přehledu nákladů pro příspěvek (§ 120e odst. 5) nemá
--     nárok na slevu na pojistném (§ 7a odst. 3 písm. e) z. 589/1992 Sb.).
--
-- Idempotentní: MODIFY COLUMN se stejným výčtem nic nemění, ADD COLUMN IF NOT
-- EXISTS přeskočí existující sloupec. Existující hodnoty zůstávají platné.

SET NAMES utf8mb4;

ALTER TABLE payroll_person_health_minimum_reductions
  MODIFY COLUMN reason ENUM(
    'state_insured',
    'ztp_or_ztp_p',
    'pension_age_without_pension',
    'sickness_care_or_quarantine',
    'osvc_minimum_advance',
    'foster_reward_only',
    'unverified',
    'child_under_7_care'
  ) NOT NULL;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS health_association_member TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Člen družstva nebo SVJ pracující za odměnu: zaměstnancem pro ZP jen v měsíci se započitatelným příjmem'
    AFTER health_insurance_participation;

ALTER TABLE payroll_absences
  MODIFY COLUMN obstacle_kind ENUM(
    'medical_examination','commute_prevented_disabled','own_wedding',
    'child_wedding','childbirth_transport','death_close_relative',
    'death_relative','family_escort','disabled_child_escort',
    'coworker_funeral','relocation_employer_interest','job_search_redundancy',
    'blood_donation','employee_representation','qualification_training',
    'other_paid_employee','downtime','weather_interruption',
    'other_employer_obstacle','partial_unemployment','partial_work'
  ) NULL
    COMMENT 'Druh placené překážky v práci (PayrollObstacleKind); určuje sazbu a složku náhrady mzdy';
