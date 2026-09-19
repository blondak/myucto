-- Převzaté mzdy: doplnění o to, co úhrnům chybělo pro evidenční list důchodového
-- pojištění (ELDP) a pro zpětnou evidenci plateb převzatého mzdového běhu.
--
-- PROČ: migrace 1849 uložila PENÍZE převzatého měsíce, protože vznikla kvůli
-- kontrolní sestavě „naše přepočtená mzda vs. převzatá". Jenže rok přechodu má
-- ještě dvě další povinnosti a ani jedna se z peněz sestavit nedá:
--
--   1. ELDP za rok přechodu. `EldpAnnualStatementBuilder` skládá list z DOB:
--      `requiredMonths($year, $start, $end)` potřebuje trvání vztahu v roce,
--      `monthLine()` počet dnů účasti, vyloučené doby, druh vztahu a druh
--      činnosti (kód ELDP je `activity_code . '++'`). Za měsíce, které vedl
--      původní program, žádná zmrazená revize neexistuje, takže tyhle veličiny
--      musí přijít odsud, nebo list za rok přechodu nevznikne.
--   2. Zpětná evidence plateb. `payroll_payment_liabilities` eviduje závazek
--      jako částku (`amount_minor`) se splatností (`due_on`) v členění
--      `liability_kind`. Pro druh `net_wage` a `deduction` v převzatém měsíci
--      nebylo z čeho vyjít: `net_minor` z migrace 1849 je čistá mzda PŘED
--      srážkami (tak ji vydává původní systém i naše sestava), ne částka, která
--      odešla na účet.
--
-- ZÁMĚRNĚ BEZ CHECKU „net_minor - deductions_minor = net_payable_minor":
-- v reálném exportu PAMICA ta rovnost NEPLATÍ (čistá mzda 34 635, srážky 2 683,
-- k výplatě 31 905 — chybějící 47 Kč je naturální plnění, které se zdaní, ale
-- nevyplácí). Vynucená rovnost by legitimní data odmítla.
--
-- ZDROJ `other`: převzaté mzdy nesmí být vázané na PAMICU. PAMICA je jen první
-- feeder; obecný tabulkový import z libovolného mzdového systému zapisuje pod
-- `other`. ENUM musí zůstat v souladu
-- s `PayrollMigrationReferenceTotalsWriter::SOURCES` (hlídá `PayrollEnumContractTest`).
--
-- Idempotence: ADD COLUMN/INDEX IF NOT EXISTS, MODIFY COLUMN je z podstaty
-- opakovatelný. CHECK constraint IF NOT EXISTS MariaDB neumí, takže se nejdřív
-- zahodí (`DROP CONSTRAINT IF EXISTS`) a přidá znovu.

SET NAMES utf8mb4;

ALTER TABLE payroll_migration_reference_totals
  MODIFY COLUMN source ENUM('pamica','pohoda','money_s3','other') NOT NULL;

ALTER TABLE payroll_migration_reference_totals
  ADD COLUMN IF NOT EXISTS relationship_start_date DATE NULL
    COMMENT 'nástup do vztahu podle původního systému (MZ.DatNast); ELDP doba trvání v roce'
    AFTER employment_id,
  ADD COLUMN IF NOT EXISTS relationship_end_date DATE NULL
    COMMENT 'skončení vztahu (MZ.DatOdch); rozhoduje o typu listu a lhůtě ELDP'
    AFTER relationship_start_date,
  ADD COLUMN IF NOT EXISTS relation_type VARCHAR(32) NULL
    COMMENT 'druh vztahu, hodnoty payroll_employments.relation_type; ELDP umí jen employment'
    AFTER relationship_end_date,
  ADD COLUMN IF NOT EXISTS activity_code VARCHAR(32) NULL
    COMMENT 'druh činnosti ČSSZ jako v payroll_employment_terms.activity_code; kód ELDP = kód + ++'
    AFTER relation_type,
  ADD COLUMN IF NOT EXISTS pension_participation TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'účast na důchodovém pojištění v měsíci (MZ.JeSocPP); 0 = měsíc není dobou pojištění'
    AFTER activity_code,
  ADD COLUMN IF NOT EXISTS insurance_days SMALLINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'dny účasti na důchodovém pojištění v měsíci'
    AFTER pension_participation,
  ADD COLUMN IF NOT EXISTS excluded_days SMALLINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'vyloučené doby § 16 odst. 4 z. č. 155/1995 Sb. (MZ.NahrDobyDP)'
    AFTER insurance_days,
  ADD COLUMN IF NOT EXISTS worked_days_hundredths INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'odpracované dny v setinách dne (MZ.DnyOdpra * 100); půldny jsou běžné'
    AFTER excluded_days,
  ADD COLUMN IF NOT EXISTS worked_minutes INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'odpracované hodiny v minutách (MZ.HodOdpra * 60); u dohod jediná měřitelná veličina'
    AFTER worked_days_hundredths,
  ADD COLUMN IF NOT EXISTS deductions_minor BIGINT NOT NULL DEFAULT 0
    COMMENT 'srážky ze mzdy celkem v haléřích (MZ.KcSrazky)'
    AFTER net_minor,
  ADD COLUMN IF NOT EXISTS net_payable_minor BIGINT NOT NULL DEFAULT 0
    COMMENT 'čistá mzda k výplatě PO srážkách v haléřích (MZ.KcVyuct); net_minor je před nimi'
    AFTER deductions_minor,
  ADD COLUMN IF NOT EXISTS payout_date DATE NULL
    COMMENT 'výplatní termín původního systému (MZ.Datum); due_on zpětně evidovaného závazku'
    AFTER tax_bonus_minor;

ALTER TABLE payroll_migration_reference_totals
  ADD INDEX IF NOT EXISTS ix_pmrt_employment (supplier_id, employment_id, period_start);

ALTER TABLE payroll_migration_reference_totals
  DROP CONSTRAINT IF EXISTS chk_pmrt_takeover_days;
ALTER TABLE payroll_migration_reference_totals
  ADD CONSTRAINT chk_pmrt_takeover_days CHECK (
    insurance_days <= 31 AND excluded_days <= 31
  );

ALTER TABLE payroll_migration_reference_totals
  DROP CONSTRAINT IF EXISTS chk_pmrt_takeover_relationship_span;
ALTER TABLE payroll_migration_reference_totals
  ADD CONSTRAINT chk_pmrt_takeover_relationship_span CHECK (
    relationship_start_date IS NULL
    OR relationship_end_date IS NULL
    OR relationship_end_date >= relationship_start_date
  );
