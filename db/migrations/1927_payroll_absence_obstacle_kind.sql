-- MyÚčto.cz — druh placené překážky v práci a sazba náhrady mzdy.
--
-- Placená překážka (lékař, svatba, úmrtí, prostoj, jiná překážka na straně
-- zaměstnavatele…) se dosud jen odečetla ze základní mzdy, ale náhrada mzdy
-- za ni nevznikla. Náhrada se počítá z průměrného výdělku a její sazbu určuje
-- druh překážky: strana zaměstnance vždy 100 % (§ 199 ZP, NV č. 590/2006 Sb.,
-- § 203, § 205), prostoj nejméně 80 % (§ 207 písm. a)), povětrnostní vlivy
-- nejméně 60 % (§ 207 písm. b)), jiná překážka 100 % (§ 208), částečná
-- nezaměstnanost nejméně 60 % podle dohody nebo vnitřního předpisu (§ 209).
--
--   * `obstacle_kind` — druh překážky z katalogu PayrollObstacleKind; NULL
--     u ostatních druhů nepřítomnosti a u překážek zapsaných před touto
--     migrací (ty se neschválí, dokud se nezapíšou znovu s druhem).
--   * `compensation_rate_reason` — důvod sazby odlišné od tabulkové nebo
--     odkaz na dohodu či vnitřní předpis (§ 209 odst. 2 ZP).
--
-- Sazba sama zůstává v existujícím `compensation_rate_basis_points`.
-- Aditivní a idempotentní (ADD COLUMN IF NOT EXISTS), existující řádky se nemění.

SET NAMES utf8mb4;

ALTER TABLE payroll_absences
  ADD COLUMN IF NOT EXISTS obstacle_kind ENUM(
    'medical_examination','commute_prevented_disabled','own_wedding',
    'child_wedding','childbirth_transport','death_close_relative',
    'death_relative','family_escort','disabled_child_escort',
    'coworker_funeral','relocation_employer_interest','job_search_redundancy',
    'blood_donation','employee_representation','qualification_training',
    'other_paid_employee','downtime','weather_interruption',
    'other_employer_obstacle','partial_unemployment'
  ) NULL
    COMMENT 'Druh placené překážky v práci (PayrollObstacleKind); určuje sazbu a složku náhrady mzdy'
    AFTER absence_type,
  ADD COLUMN IF NOT EXISTS compensation_rate_reason VARCHAR(500) NULL
    COMMENT 'Důvod sazby náhrady odlišné od tabulkové, u § 209 ZP odkaz na dohodu nebo vnitřní předpis'
    AFTER compensation_rate_basis_points;
