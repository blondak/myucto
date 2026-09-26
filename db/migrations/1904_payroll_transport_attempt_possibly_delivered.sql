-- MyÚčto.cz: pokus o odeslání ve stavu „možná doručeno".
--
-- Když odeslání na VREP spadlo až PO odeslání požadavku (vypršel čas čtení,
-- spojení se přerušilo, brána vrátila 5xx nebo nečitelnou odpověď, nebo
-- selhal zápis potvrzení), zapisoval se pokus jako `failed` bez `sent_at`.
-- Jenže `failed` bez `sent_at` znamená „nic neopustilo aplikaci" a brána
-- odeslání (PayrollDispatchGate::attemptAllowsRetry) proto pustila opakování.
-- Druhé podání se stejným obsahem ČSSZ buď odmítne jako duplicitu (20022),
-- nebo s novým GUID založí opravdovou duplicitu.
--
-- Nový stav `possibly_delivered` říká: požadavek odešel, odpověď nepřišla.
-- Neblokuje navždy, ale opakovat jde jen po výslovném potvrzení účetní
-- (pokus pak přejde do `expired` s kódem `retry_confirmed_by_user`) a znovu
-- se posílá tentýž zmrazený dokument se stejným GUID. Stejně jako u `failed`
-- musí nést kód i text chyby.
--
-- MariaDB neumí ADD CONSTRAINT IF NOT EXISTS, proto DROP + ADD (vzor 1379).
-- Opakované spuštění nic nemění.

SET NAMES utf8mb4;

ALTER TABLE payroll_submission_transport_attempts
  MODIFY COLUMN status ENUM(
    'prepared','sent','awaiting_protocol','completed','failed','expired',
    'possibly_delivered'
  ) NOT NULL DEFAULT 'prepared';

ALTER TABLE payroll_submission_transport_attempts
  DROP CONSTRAINT IF EXISTS chk_payroll_transport_attempts_failure;

ALTER TABLE payroll_submission_transport_attempts
  ADD CONSTRAINT chk_payroll_transport_attempts_failure
    CHECK (
      status NOT IN ('failed','possibly_delivered')
      OR (error_code IS NOT NULL AND error_message IS NOT NULL)
    );

ALTER TABLE payroll_submission_transport_attempts
  DROP CONSTRAINT IF EXISTS chk_payroll_transport_attempts_sent;

ALTER TABLE payroll_submission_transport_attempts
  ADD CONSTRAINT chk_payroll_transport_attempts_sent
    CHECK (
      (status = 'prepared' AND sent_at IS NULL)
      OR (
        status IN ('sent','awaiting_protocol','completed')
        AND sent_at IS NOT NULL
      )
      OR status IN ('failed','expired','possibly_delivered')
    );
