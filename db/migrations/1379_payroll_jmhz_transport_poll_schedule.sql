-- MZ-22-W07: rozvrh dotazů na protokol a doklad o uzavření transakce.
--
-- PROČ: dotaz na stav podání i uzavření transakce u VREP se dnes spouští ručně
-- z UI. Uživatel musí klikat, dokud ČSSZ protokol nevydá, a když přestane
-- klikat, podání zůstane viset ve stavu „převzato" navždy. Podací protokol
-- přitom uzavření transakce VYŽADUJE — aplikace, které transakce neuzavírají,
-- porušují pravidla provozu.
--
-- Automatika potřebuje vědět tři věci, které ledger dosud nenesl: KDY se
-- příště zeptat, KOLIKRÁT už jsme se ptali (aby existoval strop) a JESTLI je
-- transakce uzavřená. Zakládat na to druhou tabulku by znamenalo druhý zdroj
-- pravdy o jednom pokusu — proto se rozšiřuje ledger sám.
--
-- ⚠️ `next_retry_at` už v tabulce je (1372) a plnil ho jen zápis neúspěchu.
-- Nově je to obecný termín „příště se ozvi": u čekajícího pokusu termín
-- dalšího dotazu, u dokončeného termín dalšího pokusu o uzavření. Význam se
-- rozšířil, tvar ne.
--
-- CO SE NEZMĚNILO: pokus je pořád důkaz, ne stavová proměnná. Počitadla smějí
-- jen růst, `closed_at` je jednorázové přiřazení a terminální stav se nedá
-- otevřít. Jediná výjimka, kterou tahle migrace do strážce přidává, je
-- DOPSÁNÍ VÝSLEDKU UZAVŘENÍ nad už dokončeným pokusem: transakce se uzavírá až
-- po dotažení protokolu, takže jinak by doklad o uzavření neměl kam padnout a
-- musel by vzniknout mimo ledger. Nic jiného se nad terminálním řádkem měnit
-- nesmí a `expired` je uzavřený úplně.

SET NAMES utf8mb4;

ALTER TABLE payroll_submission_transport_attempts
  ADD COLUMN IF NOT EXISTS poll_count INT UNSIGNED NOT NULL DEFAULT 0
      COMMENT 'Kolikrát jsme se u VREP ptali na výsledek zpracování'
      AFTER next_retry_at,
  ADD COLUMN IF NOT EXISTS last_polled_at DATETIME NULL
      COMMENT 'Kdy proběhl poslední dotaz na stav (UTC)'
      AFTER poll_count,
  ADD COLUMN IF NOT EXISTS last_poll_error VARCHAR(500) NULL
      COMMENT 'Proč poslední dotaz na stav nedal odpověď; NULL = poslední dotaz prošel'
      AFTER last_polled_at,
  ADD COLUMN IF NOT EXISTS closed_at DATETIME NULL
      COMMENT 'Kdy byla transakce u VREP uzavřena (UTC); NULL = neuzavřena'
      AFTER completed_at,
  ADD COLUMN IF NOT EXISTS close_attempts INT UNSIGNED NOT NULL DEFAULT 0
      COMMENT 'Kolikrát jsme se pokusili transakci uzavřít'
      AFTER closed_at,
  ADD COLUMN IF NOT EXISTS close_error VARCHAR(500) NULL
      COMMENT 'Proč poslední pokus o uzavření transakce selhal'
      AFTER close_attempts;

-- Uzavřít lze jen dotažený pokus a nikdy dřív, než byl dokončen. Bez téhle
-- podmínky by šlo označit za uzavřenou transakci, o jejímž výsledku nic nevíme.
-- MariaDB neumí ADD CONSTRAINT IF NOT EXISTS, proto DROP + ADD (vzor 0021).
ALTER TABLE payroll_submission_transport_attempts
  DROP CONSTRAINT IF EXISTS chk_payroll_transport_attempts_closed;

ALTER TABLE payroll_submission_transport_attempts
  ADD CONSTRAINT chk_payroll_transport_attempts_closed
    CHECK (
      closed_at IS NULL
      OR (
        status = 'completed'
        AND completed_at IS NOT NULL
        AND closed_at >= completed_at
      )
    );

-- Fronta na pozadí se ptá napříč firmami, kdežto `idx_..._retry` z 1372 začíná
-- `supplier_id` a pro takový dotaz je nepoužitelný.
ALTER TABLE payroll_submission_transport_attempts
  ADD KEY IF NOT EXISTS idx_payroll_transport_attempts_due
    (status, next_retry_at, id);

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_transport_attempts_update_guard//
CREATE TRIGGER trg_payroll_transport_attempts_update_guard
BEFORE UPDATE ON payroll_submission_transport_attempts
FOR EACH ROW
BEGIN
  IF NOT (NEW.supplier_id <=> OLD.supplier_id)
     OR NOT (NEW.environment <=> OLD.environment)
     OR NOT (NEW.submission_id <=> OLD.submission_id)
     OR NOT (NEW.channel <=> OLD.channel)
     OR NOT (NEW.attempt_no <=> OLD.attempt_no)
     OR NOT (NEW.idempotency_key_hash <=> OLD.idempotency_key_hash)
     OR NOT (NEW.request_sha256 <=> OLD.request_sha256)
     OR NOT (NEW.created_by <=> OLD.created_by)
     OR NOT (NEW.created_at <=> OLD.created_at)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'transport attempt identity is immutable';
  END IF;

  IF NEW.row_version <> OLD.row_version + 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'transport attempt row_version must advance by one';
  END IF;

  -- Correlation reference je jednorázové přiřazení: přepsat ji znamená
  -- přehodit důkaz o odeslání na jiné podání.
  IF OLD.correlation_reference IS NOT NULL
     AND NOT (NEW.correlation_reference <=> OLD.correlation_reference)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'transport attempt correlation reference is single-assignment';
  END IF;

  -- Počitadla jsou důkaz o tom, kolikrát jsme protistranu obtěžovali. Kdyby
  -- směla klesat, dal by se jimi obejít strop pokusů a automatika by se ptala
  -- donekonečna.
  IF NEW.poll_count < OLD.poll_count OR NEW.close_attempts < OLD.close_attempts THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'transport attempt counters must not decrease';
  END IF;

  IF OLD.closed_at IS NOT NULL AND NOT (NEW.closed_at <=> OLD.closed_at) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'transport attempt closed_at is single-assignment';
  END IF;

  IF OLD.status = 'expired' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'terminal transport attempt cannot be reopened';
  END IF;

  -- Dokončený pokus smí přijmout JEDINOU věc: doklad o uzavření transakce
  -- u VREP (a jeho neúspěšné pokusy). Uzavírá se až po dotažení protokolu,
  -- takže dřív než tady se ten doklad nemá kam zapsat.
  IF OLD.status = 'completed' THEN
    IF NOT (NEW.status <=> OLD.status)
       OR NOT (NEW.correlation_reference <=> OLD.correlation_reference)
       OR NOT (NEW.response_http_status <=> OLD.response_http_status)
       OR NOT (NEW.error_code <=> OLD.error_code)
       OR NOT (NEW.error_message <=> OLD.error_message)
       OR NOT (NEW.sent_at <=> OLD.sent_at)
       OR NOT (NEW.completed_at <=> OLD.completed_at)
       OR NOT (NEW.poll_count <=> OLD.poll_count)
       OR NOT (NEW.last_polled_at <=> OLD.last_polled_at)
       OR NOT (NEW.last_poll_error <=> OLD.last_poll_error)
    THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'completed transport attempt accepts only the closing record';
    END IF;
  END IF;

  -- Jednou odeslaný pokus zůstává odeslaný. Bez tohohle šlo pokus vrátit
  -- z 'sent' na 'prepared' a vynulovat sent_at, čímž zmizel důkaz o tom,
  -- že zpráva u ČSSZ byla — a ledger, který smí zapomenout odeslání, není
  -- ledger, ale stavová proměnná.
  IF OLD.sent_at IS NOT NULL AND NOT (NEW.sent_at <=> OLD.sent_at) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'transport attempt sent_at is single-assignment';
  END IF;

  IF OLD.status <> 'prepared' AND NEW.status = 'prepared' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'transport attempt cannot return to prepared';
  END IF;
END//

DELIMITER ;
