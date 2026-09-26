-- MyÚčto.cz — exekuce při skončení pracovního poměru a vydání depozita
-- insolvenčnímu správci.
--
-- 1) Oznámení soudu / exekutorovi o skončení poměru (§ 295 odst. 2 o. s. ř.,
--    shodně § 52 odst. 1 exekučního řádu): plátce mzdy do jednoho týdne oznámí,
--    že u něj povinný přestal pracovat, a zašle vyúčtování provedených
--    a vyplacených srážek s výčtem pohledávek a jejich pořadí. Aplikace to
--    nevedla vůbec. Nová tabulka drží zmrazený obsah oznámení (z něj se
--    deterministicky vykresluje PDF), termín a způsob odeslání.
--
-- 2) Stav případu `ended_at_payer`: srážení u TOHOTO plátce skončilo, protože
--    skončil pracovní poměr. Dosud šlo případ ukončit jen příkazem `stop`, který
--    vyžaduje soudní rozhodnutí o zastavení exekuce — jenže exekuce zastavena
--    není, jen pokračuje jinde.
--
-- 3) Vydání depozita insolvenčnímu správci: sražené a deponované částky ze
--    zahájeného insolvenčního řízení (§ 109 odst. 1 písm. c) IZ, R 4/2020)
--    patří po schválení oddlužení nebo prohlášení konkursu do majetkové
--    podstaty. Ledger umí depozitum uvolnit jen oprávněnému nebo vrátit
--    zaměstnanci; nový pohyb `released_to_administrator` nese i účet správce
--    z katalogu příjemců, ze kterého vznikne platební závazek.
--
-- IDEMPOTENCE: MODIFY ENUM je opakovatelný, sloupce a klíče přes IF [NOT]
-- EXISTS, CHECK se nejdřív zahazuje (MariaDB neumí ADD CONSTRAINT IF NOT
-- EXISTS u CHECK), triggery DROP + CREATE.

SET NAMES utf8mb4;

-- ── 1) Stav případu ─────────────────────────────────────────────────────────
ALTER TABLE payroll_enforcement_cases
  MODIFY COLUMN status ENUM(
    'received','withhold_and_hold','remit',
    'deferred_no_withholding','deferred_hold',
    'paid','stopped','ended_at_payer'
  ) NOT NULL DEFAULT 'received';

-- ── 2) Ledger: vydání insolvenčnímu správci ─────────────────────────────────
ALTER TABLE payroll_enforcement_ledger
  MODIFY COLUMN entry_kind ENUM(
    'withheld','held','released_for_remittance','remitted',
    'released_to_employee','employer_fee','adjustment',
    'released_to_administrator'
  ) NOT NULL,
  ADD COLUMN IF NOT EXISTS recipient_account_id BIGINT UNSIGNED NULL
    AFTER decision_event_id,
  ADD KEY IF NOT EXISTS idx_payroll_enforcement_ledger_recipient_account
    (supplier_id, recipient_account_id);

ALTER TABLE payroll_enforcement_ledger
  DROP CONSTRAINT IF EXISTS chk_payroll_enforcement_ledger_owner;
ALTER TABLE payroll_enforcement_ledger
  ADD CONSTRAINT chk_payroll_enforcement_ledger_owner
    CHECK (
      (entry_kind = 'employer_fee' AND case_id IS NULL AND claim_id IS NULL)
      OR (
        entry_kind IN ('released_for_remittance', 'released_to_administrator')
        AND case_id IS NOT NULL AND claim_id IS NOT NULL
      )
      OR (
        entry_kind IN (
          'withheld','held','remitted','released_to_employee'
        )
        AND ((case_id IS NULL AND claim_id IS NULL)
          OR (case_id IS NOT NULL AND claim_id IS NOT NULL))
      )
      OR (
        entry_kind = 'adjustment'
        AND (claim_id IS NULL OR case_id IS NOT NULL)
      )
    );

ALTER TABLE payroll_enforcement_ledger
  DROP CONSTRAINT IF EXISTS chk_payroll_enforcement_ledger_decision_event;
ALTER TABLE payroll_enforcement_ledger
  ADD CONSTRAINT chk_payroll_enforcement_ledger_decision_event
    CHECK (
      (entry_kind IN ('released_for_remittance', 'released_to_administrator')
        AND decision_event_id IS NOT NULL)
      OR (entry_kind NOT IN ('released_for_remittance', 'released_to_administrator')
        AND decision_event_id IS NULL)
    );

ALTER TABLE payroll_enforcement_ledger
  DROP CONSTRAINT IF EXISTS chk_payroll_enforcement_ledger_recipient_account;
ALTER TABLE payroll_enforcement_ledger
  ADD CONSTRAINT chk_payroll_enforcement_ledger_recipient_account
    CHECK (
      (entry_kind = 'released_to_administrator' AND recipient_account_id IS NOT NULL)
      OR (entry_kind <> 'released_to_administrator' AND recipient_account_id IS NULL)
    );

ALTER TABLE payroll_enforcement_ledger
  DROP FOREIGN KEY IF EXISTS fk_payroll_enforcement_ledger_recipient_account;
ALTER TABLE payroll_enforcement_ledger
  ADD CONSTRAINT fk_payroll_enforcement_ledger_recipient_account
    FOREIGN KEY (supplier_id, recipient_account_id)
    REFERENCES payroll_institution_accounts (supplier_id, id)
    ON DELETE RESTRICT;

-- ── 3) Oznámení o skončení poměru ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payroll_enforcement_termination_notices (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  case_id              BIGINT UNSIGNED NOT NULL,
  employee_id          BIGINT UNSIGNED NOT NULL,
  employment_id        BIGINT UNSIGNED NULL,
  revision_no          INT UNSIGNED NOT NULL,
  employment_ended_on  DATE NOT NULL,
  due_on               DATE NOT NULL
                       COMMENT '§ 295 odst. 2 o. s. ř. — do jednoho týdne od skončení',
  new_payer_name       VARCHAR(255) NULL,
  new_payer_reference  VARCHAR(128) NULL,
  snapshot_json        LONGTEXT NOT NULL,
  snapshot_hash        CHAR(64) NOT NULL,
  sent_on              DATE NULL,
  sent_channel         ENUM('isds','post','personal','other') NULL,
  outbox_id            BIGINT UNSIGNED NULL,
  created_by           BIGINT UNSIGNED NULL,
  sent_by              BIGINT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payroll_enforcement_termination_notice_revision
    (supplier_id, case_id, revision_no),
  UNIQUE KEY uq_payroll_enforcement_termination_notice_tenant_id
    (supplier_id, id),
  KEY idx_payroll_enforcement_termination_notice_employee
    (supplier_id, employee_id, employment_ended_on),
  CONSTRAINT fk_payroll_enforcement_termination_notice_case
    FOREIGN KEY (supplier_id, case_id)
    REFERENCES payroll_enforcement_cases (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_termination_notice_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_termination_notice_outbox
    FOREIGN KEY (supplier_id, outbox_id)
    REFERENCES submission_outbox (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_enforcement_termination_notice_sent
    CHECK ((sent_on IS NULL) = (sent_channel IS NULL)),
  CONSTRAINT chk_payroll_enforcement_termination_notice_due
    CHECK (due_on >= employment_ended_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Oznámení se posílá datovou schránkou přes společnou frontu podání.
ALTER TABLE submission_outbox
  MODIFY COLUMN artifact_kind
    ENUM('payroll_submission','tax_submission','document','payroll_xmlzam',
         'payroll_enforcement_notice') NOT NULL;

DELIMITER //

-- Rozhodnutí o vydání depozita správci (schválení oddlužení / prohlášení
-- konkursu) se klasifikuje jako `deferment`: od téhož okamžiku se exekuce
-- u plátce nevykonává. Definice jinak doslova z 1247.
DROP TRIGGER IF EXISTS trg_payroll_enforcement_event_document_insert//

CREATE TRIGGER trg_payroll_enforcement_event_document_insert
BEFORE INSERT ON payroll_enforcement_events
FOR EACH ROW
BEGIN
  IF NEW.decision_document_id IS NULL
     AND (NEW.decision_evidence_hash IS NOT NULL
       OR NEW.decision_case_document_id IS NOT NULL) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement decision reference is incomplete';
  END IF;

  IF NEW.decision_document_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
      FROM payroll_enforcement_case_documents case_document
     WHERE case_document.supplier_id = NEW.supplier_id
       AND case_document.id = NEW.decision_case_document_id
       AND case_document.case_id = NEW.case_id
       AND case_document.dms_document_id = NEW.decision_document_id
       AND case_document.document_sha256 = NEW.decision_evidence_hash
       AND case_document.evidence_kind = CASE NEW.command_name
         WHEN 'mark_final' THEN 'initial_order'
         WHEN 'authorize_remittance' THEN 'remittance'
         WHEN 'defer_no_withholding' THEN 'deferment'
         WHEN 'defer_hold' THEN 'deferment'
         WHEN 'release_to_administrator' THEN 'deferment'
         WHEN 'resume_holding' THEN 'resumption'
         WHEN 'resume_remittance' THEN 'resumption'
         WHEN 'stop' THEN 'termination'
         ELSE ''
       END
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement decision classification mismatch';
  END IF;
END//

-- Definice z 1738 rozšířená o `released_to_administrator`: pohyb musí mít
-- rozhodnutí `release_to_administrator` k témuž případu a spolu s ostatními
-- uvolněními nesmí přesáhnout deponovanou částku.
DROP TRIGGER IF EXISTS trg_payroll_enforcement_ledger_consistency_insert//

CREATE TRIGGER trg_payroll_enforcement_ledger_consistency_insert
BEFORE INSERT ON payroll_enforcement_ledger
FOR EACH ROW
BEGIN
  DECLARE allocation_total BIGINT DEFAULT NULL;
  DECLARE held_total BIGINT DEFAULT 0;
  DECLARE released_total BIGINT DEFAULT 0;
  DECLARE remitted_total BIGINT DEFAULT 0;
  DECLARE returned_total BIGINT DEFAULT 0;
  DECLARE handed_total BIGINT DEFAULT 0;
  DECLARE result_fee BIGINT DEFAULT NULL;
  DECLARE decision_case_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE decision_command VARCHAR(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE decision_document_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE decision_evidence_hash CHAR(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL;

  IF NEW.entry_kind IN ('released_for_remittance', 'released_to_administrator') THEN
    SELECT decision.case_id, decision.command_name,
           decision.decision_document_id, decision.decision_evidence_hash
      INTO decision_case_id, decision_command, decision_document_id,
           decision_evidence_hash
      FROM payroll_enforcement_events decision
     WHERE decision.supplier_id = NEW.supplier_id
       AND decision.id = NEW.decision_event_id;

    IF decision_case_id IS NULL
       OR decision_case_id <> NEW.case_id
       OR decision_command IS NULL
       OR (NEW.entry_kind = 'released_for_remittance'
           AND decision_command NOT IN ('authorize_remittance','resume_remittance'))
       OR (NEW.entry_kind = 'released_to_administrator'
           AND decision_command <> 'release_to_administrator')
       OR decision_document_id IS NULL
       OR decision_evidence_hash IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll enforcement release has no matching decision';
    END IF;
  END IF;

  IF NEW.entry_kind = 'employer_fee' THEN
    SELECT employer_fee_minor_units
      INTO result_fee
      FROM payroll_enforcement_month_results
     WHERE supplier_id = NEW.supplier_id
       AND id = NEW.month_result_id;

    IF result_fee IS NULL OR NEW.amount_minor_units <> result_fee THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll enforcement employer fee does not match result';
    END IF;
  ELSEIF NEW.entry_kind IN (
    'withheld','held','released_for_remittance','remitted',
    'released_to_employee','released_to_administrator'
  ) THEN
    SELECT total_minor_units
      INTO allocation_total
      FROM payroll_enforcement_allocations
     WHERE supplier_id = NEW.supplier_id
       AND month_result_id = NEW.month_result_id
       AND case_id <=> NEW.case_id
       AND claim_id <=> NEW.claim_id
     LIMIT 1;

    IF allocation_total IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll enforcement ledger has no matching allocation';
    END IF;

    IF NEW.entry_kind IN ('withheld','held')
       AND NEW.amount_minor_units <> allocation_total THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll enforcement calculation entry differs from allocation';
    END IF;

    SELECT COALESCE(SUM(CASE WHEN entry_kind = 'held'
           THEN amount_minor_units ELSE 0 END), 0),
           COALESCE(SUM(CASE WHEN entry_kind = 'released_for_remittance'
             THEN amount_minor_units ELSE 0 END), 0),
           COALESCE(SUM(CASE WHEN entry_kind = 'remitted'
             THEN amount_minor_units ELSE 0 END), 0),
           COALESCE(SUM(CASE WHEN entry_kind = 'released_to_employee'
             THEN amount_minor_units ELSE 0 END), 0),
           COALESCE(SUM(CASE WHEN entry_kind = 'released_to_administrator'
             THEN amount_minor_units ELSE 0 END), 0)
      INTO held_total, released_total, remitted_total, returned_total,
           handed_total
      FROM payroll_enforcement_ledger
     WHERE supplier_id = NEW.supplier_id
       AND month_result_id = NEW.month_result_id
       AND case_id <=> NEW.case_id
       AND claim_id <=> NEW.claim_id;

    IF NEW.entry_kind IN (
      'released_for_remittance','released_to_employee','released_to_administrator'
    ) THEN
      IF returned_total
           + IF(NEW.entry_kind = 'released_to_employee', NEW.amount_minor_units, 0)
           + handed_total
           + IF(NEW.entry_kind = 'released_to_administrator', NEW.amount_minor_units, 0)
           + GREATEST(
               released_total
                 + IF(NEW.entry_kind = 'released_for_remittance',
                      NEW.amount_minor_units, 0),
               remitted_total
             ) > held_total THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Payroll enforcement held amount is over-disposed';
      END IF;
    ELSEIF NEW.entry_kind = 'remitted' THEN
      IF remitted_total + returned_total + handed_total + NEW.amount_minor_units
           > allocation_total THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Payroll enforcement allocation is over-remitted';
      END IF;
    END IF;
  ELSEIF NEW.entry_kind = 'adjustment' AND NEW.actor_user_id IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement adjustment requires an actor';
  END IF;
END//

DELIMITER ;
