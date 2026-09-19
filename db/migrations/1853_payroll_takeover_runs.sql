-- MyÚčto.cz — PAM-17 / PAM-18: převzatý mzdový běh a zpětná evidence jeho plateb.
--
-- PROČ
--
-- Měsíce, které zpracoval předchozí mzdový program, v MyÚčtu dosud neexistovaly
-- jako mzdový běh a ani vzniknout nemohly: `PayrollRunCommandService` odmítne
-- období před `payroll_module_state.start_period` a `PayrollRunWorkflow` má
-- tvrdé předpoklady, které se nedají přebít výjimkou. Za rok přechodu tak nebylo
-- kam pověsit doložení, že mzdy a odvody za leden až červen někdo vyplatil.
--
-- CO SE ZAVÁDÍ
--
-- 1. `payroll_runs.run_kind` — druh běhu. `calculated` je všechno, co dnes
--    existuje (výchozí hodnota, takže stávající řádky se nehnou), `takeover` je
--    zrcadlo cizího výpočtu.
--
-- 2. `payroll_takeover_runs` — zmrazený převzatý výsledek. ZÁMĚRNĚ to NENÍ
--    `payroll_run_revisions`: revize je vstupenka do ročního zúčtování, ELDP,
--    JMHZ, mzdových listů, výplatních pásek, kontrolních součtů i platebního
--    ledgeru, a všichni tihle čtenáři filtrují na `status = 'approved'`. Kdyby
--    převzatý výsledek ležel tam, stačilo by ho označit za schválený a odešel by
--    do zákonného tiskopisu jako doložený údaj. Fail-closed je jediná bezpečná
--    volba: převzatý výsledek leží mimo revize a čtenáři se k němu musí přihlásit.
--    Sankcionovaná cesta k převzatým číslům je `PayrollTakeoverReader`, ne druhá,
--    tichá kopie v revizích.
--
-- 3. `payroll_takeover_payment_evidence` — doložení, že se za historický měsíc
--    platilo. NEVSTUPUJE do platebního ledgeru: závazek (`payroll_payment_liabilities`)
--    je nárok, který má MyÚčto teprve zaplatit, a jeho uzavření vyžaduje skutečný
--    bankovní pohyb nebo pokladní doklad (`chk_payroll_payment_match_evidence`).
--    Historická platba ani jedno nemá, takže by závazek zůstal navždy otevřený
--    v saldu a v hlídači termínů. Evidence je proto samostatná a pouze evidenční.
--
-- GUARDY (obrana do hloubky, vázaná na DRUH BĚHU, ne na soubor)
--
--  * revize nad převzatým během nevznikne ani ručním INSERTem,
--  * účetní dávka nad převzatým během nevznikne — doklady za historická období
--    jsou v účetnictví už z převodu a druhý zápis by je zdvojil,
--  * `run_kind` je po založení neměnný,
--  * zmrazený převzatý výsledek i evidence plateb se nedají přepsat. Smazat ano:
--    převzatý běh není doklad, je to zrcadlo — opravuje se zrušením a novým
--    převzetím.

SET NAMES utf8mb4;

ALTER TABLE payroll_runs
  ADD COLUMN IF NOT EXISTS run_kind ENUM('calculated', 'takeover')
    NOT NULL DEFAULT 'calculated' AFTER status;

CREATE TABLE IF NOT EXISTS payroll_takeover_runs (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  run_id               BIGINT UNSIGNED NOT NULL,
  period_start         DATE NOT NULL,
  takeover_sources     VARCHAR(190) NOT NULL,
  input_snapshot_json  LONGTEXT NOT NULL CHECK (JSON_VALID(input_snapshot_json)),
  input_snapshot_hash  CHAR(64) NOT NULL,
  result_snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(result_snapshot_json)),
  result_snapshot_hash CHAR(64) NOT NULL,
  employee_count       INT UNSIGNED NOT NULL,
  relationship_count   INT UNSIGNED NOT NULL,
  built_by             BIGINT UNSIGNED NULL,
  built_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_takeover_run (supplier_id, run_id),
  UNIQUE KEY uq_payroll_takeover_run_supplier_id (supplier_id, id),
  KEY idx_payroll_takeover_run_period (supplier_id, period_start),
  CONSTRAINT fk_payroll_takeover_run_run
    FOREIGN KEY (supplier_id, run_id)
    REFERENCES payroll_runs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_takeover_run_user
    FOREIGN KEY (built_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_takeover_run_period CHECK (DAYOFMONTH(period_start) = 1),
  CONSTRAINT chk_payroll_takeover_run_hashes CHECK (
    input_snapshot_hash REGEXP '^[0-9a-f]{64}$'
    AND result_snapshot_hash REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_takeover_payment_evidence (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  run_id              BIGINT UNSIGNED NOT NULL,
  evidence_kind       ENUM(
    'net_wage', 'deduction', 'social_insurance', 'health_insurance',
    'advance_tax', 'withholding_tax', 'tax_bonus'
  ) NOT NULL,
  -- `reported` = částku i datum nese sám převzatý záznam (výplata čisté mzdy má
  -- v převzatých datech `payout_date`). `derived` = částka je součet složek
  -- převzaté mzdy, ne doklad o odeslané platbě; datum se u ní nevymýšlí.
  certainty           ENUM('reported', 'derived') NOT NULL,
  employee_id         BIGINT UNSIGNED NULL,
  external_person_ref VARCHAR(64) NOT NULL DEFAULT '' COLLATE utf8mb4_bin,
  person_scope        BIGINT UNSIGNED AS (COALESCE(employee_id, 0)) STORED,
  amount_minor        BIGINT NOT NULL,
  currency_code       CHAR(3) NOT NULL DEFAULT 'CZK',
  paid_on             DATE NULL,
  created_by          BIGINT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_takeover_payment (
    supplier_id, run_id, evidence_kind, person_scope, external_person_ref
  ),
  UNIQUE KEY uq_payroll_takeover_payment_supplier_id (supplier_id, id),
  KEY idx_payroll_takeover_payment_run (supplier_id, run_id, evidence_kind),
  CONSTRAINT fk_payroll_takeover_payment_run
    FOREIGN KEY (supplier_id, run_id)
    REFERENCES payroll_runs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_takeover_payment_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_takeover_payment_user
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_takeover_payment_currency CHECK (
    currency_code REGEXP '^[A-Z]{3}$'
  ),
  -- Datum smí nést jen to, co ho doopravdy má odkud vzít.
  CONSTRAINT chk_payroll_takeover_payment_date CHECK (
    paid_on IS NULL OR certainty = 'reported'
  ),
  -- Osobní řádek musí jít přiřadit k člověku. `employee_id` je `NULL`, když se
  -- osoba do MyÚčta nepřevedla — pak ji drží identita z původního systému.
  CONSTRAINT chk_payroll_takeover_payment_person CHECK (
    evidence_kind <> 'net_wage'
    OR employee_id IS NOT NULL
    OR external_person_ref <> ''
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_payroll_run_revision_takeover_block;
DROP TRIGGER IF EXISTS trg_payroll_posting_batch_takeover_block;
DROP TRIGGER IF EXISTS trg_payroll_takeover_run_validate_insert;
DROP TRIGGER IF EXISTS trg_payroll_takeover_run_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_takeover_payment_validate_insert;
DROP TRIGGER IF EXISTS trg_payroll_takeover_payment_immutable_update;

DELIMITER //

-- Bez tohohle triggeru by celá bezpečnost převzatého běhu stála na tom, že si
-- žádná budoucí cesta nevšimne, že `payroll_run_revisions` je otevřená. Revize
-- nad převzatým během je vstupenka do zákonných tiskopisů, a ta se nevydává.
CREATE TRIGGER trg_payroll_run_revision_takeover_block
BEFORE INSERT ON payroll_run_revisions
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_runs run
     WHERE run.supplier_id = NEW.supplier_id
       AND run.id = NEW.run_id
       AND run.run_kind = 'takeover'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Takeover payroll run cannot have a calculated revision';
  END IF;
END//

-- Doklady za historická období jsou v účetnictví už z převodu. Druhá účetní
-- dávka nad týmž měsícem by je zdvojila.
CREATE TRIGGER trg_payroll_posting_batch_takeover_block
BEFORE INSERT ON payroll_posting_batches
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_runs run
     WHERE run.supplier_id = NEW.supplier_id
       AND run.id = NEW.run_id
       AND run.run_kind = 'takeover'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Takeover payroll run must not be posted again';
  END IF;
END//

CREATE TRIGGER trg_payroll_takeover_run_validate_insert
BEFORE INSERT ON payroll_takeover_runs
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_runs run
     WHERE run.supplier_id = NEW.supplier_id
       AND run.id = NEW.run_id
       AND run.run_kind = 'takeover'
       AND run.period_start = NEW.period_start
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Takeover snapshot requires a takeover run of the same period';
  END IF;
END//

CREATE TRIGGER trg_payroll_takeover_run_immutable_update
BEFORE UPDATE ON payroll_takeover_runs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Takeover payroll snapshots are immutable';
END//

CREATE TRIGGER trg_payroll_takeover_payment_validate_insert
BEFORE INSERT ON payroll_takeover_payment_evidence
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_takeover_runs takeover
     WHERE takeover.supplier_id = NEW.supplier_id
       AND takeover.run_id = NEW.run_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Takeover payment evidence requires a takeover snapshot';
  END IF;
END//

CREATE TRIGGER trg_payroll_takeover_payment_immutable_update
BEFORE UPDATE ON payroll_takeover_payment_evidence
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Takeover payment evidence is immutable';
END//

-- Přebírá guard z migrace 1632 a přidává do neměnné identity `run_kind`.
-- Zbytek těla je beze změny — kopie je vědomá: MariaDB neumí trigger doplnit,
-- jen nahradit, a druhý BEFORE UPDATE trigger nad týmž řádkem by rozdělil
-- jedno pravidlo do dvou souborů.
CREATE OR REPLACE TRIGGER trg_payroll_run_update_guard
BEFORE UPDATE ON payroll_runs
FOR EACH ROW
BEGIN
  DECLARE frozen_revisions INT DEFAULT 0;
  DECLARE posting_batches INT DEFAULT 0;

  IF NOT (
    NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.office_id <=> OLD.office_id
    AND NEW.created_by <=> OLD.created_by
    AND NEW.created_at <=> OLD.created_at
    AND NEW.run_kind <=> OLD.run_kind
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll run identity is immutable';
  END IF;

  IF NEW.row_version < OLD.row_version THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll run row_version must not go backwards';
  END IF;

  IF NEW.current_revision_no < OLD.current_revision_no THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll run revision pointer must not go backwards';
  END IF;

  IF NOT (NEW.period_start <=> OLD.period_start)
     OR NOT (NEW.payment_date <=> OLD.payment_date)
  THEN
    SELECT COUNT(*) INTO frozen_revisions
      FROM payroll_run_revisions revision
     WHERE revision.supplier_id = OLD.supplier_id
       AND revision.run_id = OLD.id
       AND revision.status IN ('approved', 'superseded');

    SELECT COUNT(*) INTO posting_batches
      FROM payroll_posting_batches batch
     WHERE batch.supplier_id = OLD.supplier_id
       AND batch.run_id = OLD.id;

    IF frozen_revisions > 0 OR posting_batches > 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Approved payroll run period and payment date are frozen';
    END IF;
  END IF;
END//

DELIMITER ;
