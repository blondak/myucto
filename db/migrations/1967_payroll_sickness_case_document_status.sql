-- MyUcto.cz - případ dávky: stav po dokumentech (NEMPRI a HZUPN zvlášť),
-- převzetí z předchozího programu, podklady DLO a důvod převedení.
--
-- ## Proč stav po dokumentech
--
-- NEMPRI (§ 97 odst. 1 a 2 zák. č. 187/2006 Sb.) a HZUPN (§ 97 odst. 3) jsou
-- dvě samostatná podání se samostatnými lhůtami. Případ měl jediný sloupec
-- `status` s jediným `accepted_on`: po zapsání přijetí NEMPRI se případ zamkl,
-- vypadl z hlídače lhůt (lhůta HZUPN se pak nehlídala) a navazující absence
-- založila nový případ, takže se jedna událost rozdělila na dvě.
--
-- Každé podání proto nese vlastní stav, den doručení a důvod odmítnutí.
-- Společný stav případu se jen ODVOZUJE (virtuální sloupec `status`), neukládá:
-- uložený souhrn by se dřív nebo později rozešel s tím, z čeho vznikl.
--
-- Stav podání:
--   pending      ještě nedoručeno (případně připravené, viz *_submission_id),
--   accepted     doručeno, den doručení z protokolu ČSSZ je povinný,
--   rejected     odmítnuto, důvod z protokolu je povinný,
--   predecessor  podal předchozí mzdový program; MyÚčto nic nepodává.
--
-- ## Převod stávajících dat
--
-- Dosavadní `accepted` znamenalo přijetí NEMPRI (přijetí se zapisovalo
-- u NEMPRI a tím se případ zamkl), takže se převádí na `nempri_status`.
-- HZUPN zůstane nedoručené: den jeho doručení v datech není a vymyslet ho
-- nelze. Případ se tak znovu objeví v hlídači a přijetí HZUPN se zapíše ručně.
-- `rejected` se převádí na odmítnuté NEMPRI s původním důvodem, `cancelled`
-- na zrušený případ. Migrace je opakovatelná: staré sloupce se před převodem
-- jen dočasně doplní (při opakování prázdné), takže druhý běh nic nezmění.
--
-- ## Převzetí z předchozího programu
--
-- `source = predecessor` označí případ, který založil převod mezd nebo který
-- vznikl k události z doby před začátkem vedení mezd v MyÚčtu.
-- `external_reference` drží odkaz na původ (převzatá nepřítomnost, soubor).
--
-- ## Podklady DLO a převedení na jinou práci
--
-- DV NEMPRI25 (Podklady pro výplatu DLO) chce u trvání a ukončení `maVolno`,
-- při něm `pracovniVolno` a při plánovaných směnách `seznamRozvrhuSmen`.
-- Období se ukládají jako JSON pole {from,to} stejně jako `care_days`.
-- `transfer_reason` je důvod převedení na jinou práci (§ 19 odst. 6:
-- těhotenství, mateřství, kojení); rozhodné období k převedení počítá výpočet.

SET NAMES utf8mb4;

ALTER TABLE payroll_sickness_cases
  ADD COLUMN IF NOT EXISTS nempri_status
    ENUM('pending','accepted','rejected','predecessor') NOT NULL DEFAULT 'pending'
    AFTER additional_note,
  ADD COLUMN IF NOT EXISTS nempri_accepted_on DATE NULL AFTER nempri_status,
  ADD COLUMN IF NOT EXISTS nempri_rejection_reason VARCHAR(190) NULL
    AFTER nempri_accepted_on,
  ADD COLUMN IF NOT EXISTS hzupn_status
    ENUM('pending','accepted','rejected','predecessor') NOT NULL DEFAULT 'pending'
    AFTER nempri_rejection_reason,
  ADD COLUMN IF NOT EXISTS hzupn_accepted_on DATE NULL AFTER hzupn_status,
  ADD COLUMN IF NOT EXISTS hzupn_rejection_reason VARCHAR(190) NULL
    AFTER hzupn_accepted_on,
  ADD COLUMN IF NOT EXISTS cancelled TINYINT(1) NOT NULL DEFAULT 0
    AFTER hzupn_rejection_reason,
  ADD COLUMN IF NOT EXISTS source ENUM('myucto','predecessor') NOT NULL DEFAULT 'myucto'
    AFTER cancelled,
  ADD COLUMN IF NOT EXISTS external_reference VARCHAR(190) NULL AFTER source,
  ADD COLUMN IF NOT EXISTS dlo_has_leave TINYINT(1) NULL AFTER planned_shifts_worked,
  ADD COLUMN IF NOT EXISTS dlo_leave_periods JSON NULL AFTER dlo_has_leave,
  ADD COLUMN IF NOT EXISTS dlo_shift_schedule JSON NULL AFTER dlo_leave_periods,
  ADD COLUMN IF NOT EXISTS transfer_reason VARCHAR(16) NULL AFTER transferred_on;

-- Dočasné doplnění starých sloupců: při prvním běhu existují s daty, při
-- opakování vzniknou prázdné a převod níž je přeskočí.
ALTER TABLE payroll_sickness_cases
  ADD COLUMN IF NOT EXISTS accepted_on DATE NULL,
  ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(190) NULL;

ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_accepted;
ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_rejected;

UPDATE payroll_sickness_cases
   SET nempri_status = 'accepted',
       nempri_accepted_on = accepted_on,
       nempri_rejection_reason = NULL
 WHERE status = 'accepted'
   AND accepted_on IS NOT NULL;

UPDATE payroll_sickness_cases
   SET nempri_status = 'rejected',
       nempri_accepted_on = NULL,
       nempri_rejection_reason = rejection_reason
 WHERE status = 'rejected'
   AND rejection_reason IS NOT NULL;

UPDATE payroll_sickness_cases
   SET cancelled = 1
 WHERE status = 'cancelled'
   AND cancelled = 0;

ALTER TABLE payroll_sickness_cases
  DROP INDEX IF EXISTS idx_payroll_sickness_case_status;

ALTER TABLE payroll_sickness_cases
  DROP COLUMN IF EXISTS accepted_on,
  DROP COLUMN IF EXISTS rejection_reason,
  DROP COLUMN IF EXISTS status;

-- Společný stav případu, jen odvozený:
--   cancelled  případ zrušen,
--   rejected   některé podání odmítnuto a čeká na nové,
--   accepted   všechna podání vyřízena (HZUPN jen u nemocenského),
--   submitted  část vyřízena, další podání čeká,
--   prepared   podání připravené, nic ještě nedoručeno,
--   draft      nic připravené.
ALTER TABLE payroll_sickness_cases
  ADD COLUMN IF NOT EXISTS status
    ENUM('draft','prepared','submitted','accepted','rejected','cancelled')
    GENERATED ALWAYS AS (
      CASE
        WHEN cancelled = 1 THEN 'cancelled'
        WHEN nempri_status = 'rejected' OR hzupn_status = 'rejected' THEN 'rejected'
        WHEN nempri_status IN ('accepted','predecessor')
             AND (benefit_kind <> 'NEM' OR hzupn_status IN ('accepted','predecessor'))
          THEN 'accepted'
        WHEN nempri_status IN ('accepted','predecessor')
             OR hzupn_status IN ('accepted','predecessor')
          THEN 'submitted'
        WHEN nempri_submission_id IS NOT NULL OR hzupn_submission_id IS NOT NULL
          THEN 'prepared'
        ELSE 'draft'
      END
    ) VIRTUAL
    AFTER external_reference;

-- MariaDB neumí IF NOT EXISTS u CHECK, takže se každé omezení nejdřív zahodí.

-- Přijetí nese den doručení, odmítnutí důvod. Předchozí program den doručení
-- mít může, ale nemusí.
ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_nempri_receipt;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_nempri_receipt
    CHECK (
      (nempri_status = 'accepted' AND nempri_accepted_on IS NOT NULL
        AND nempri_rejection_reason IS NULL)
      OR (nempri_status = 'rejected' AND nempri_accepted_on IS NULL
        AND nempri_rejection_reason IS NOT NULL)
      OR (nempri_status = 'predecessor' AND nempri_rejection_reason IS NULL)
      OR (nempri_status = 'pending' AND nempri_accepted_on IS NULL
        AND nempri_rejection_reason IS NULL)
    );

ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_hzupn_receipt;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_hzupn_receipt
    CHECK (
      (hzupn_status = 'accepted' AND hzupn_accepted_on IS NOT NULL
        AND hzupn_rejection_reason IS NULL)
      OR (hzupn_status = 'rejected' AND hzupn_accepted_on IS NULL
        AND hzupn_rejection_reason IS NOT NULL)
      OR (hzupn_status = 'predecessor' AND hzupn_rejection_reason IS NULL)
      OR (hzupn_status = 'pending' AND hzupn_accepted_on IS NULL
        AND hzupn_rejection_reason IS NULL)
    );

-- HZUPN vzniká jen u nemocenského (§ 97 odst. 3, nástup po skončení
-- neschopnosti); u jiné dávky by vyřízené HZUPN tvrdilo podání, které nebylo.
ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_hzupn_kind;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_hzupn_kind
    CHECK (benefit_kind = 'NEM' OR hzupn_status = 'pending');

ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_transfer_reason;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_transfer_reason
    CHECK (
      transfer_reason IS NULL
      OR (transfer_reason IN ('pregnancy','maternity','breastfeeding')
        AND transferred_other_work = 1)
    );

-- Podklady pro výplatu DLO patří jen dlouhodobému ošetřovnému; období volna
-- jen k příznaku `maVolno`.
ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_dlo_basis;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_dlo_basis
    CHECK (
      (benefit_kind = 'DLO'
        AND (dlo_leave_periods IS NULL OR dlo_has_leave = 1))
      OR (dlo_has_leave IS NULL AND dlo_leave_periods IS NULL
        AND dlo_shift_schedule IS NULL)
    );

ALTER TABLE payroll_sickness_cases
  ADD INDEX IF NOT EXISTS idx_payroll_sickness_case_documents
    (supplier_id, environment, cancelled, nempri_status, hzupn_status);
