-- MyÚčto.cz — drobné mezery mzdové agendy, druhá část (audit 26. 9. 2026).
--
-- ── Den přiznání průkazu ZTP/P u vyživovaného dítěte ─────────────────────────
-- Dvojnásobné zvýhodnění (§ 35c odst. 7 ZDP) náleží za kalendářní měsíc, na
-- jehož počátku byl průkaz ZTP/P přiznán (§ 35c odst. 10). Nárok se eviduje
-- po celých měsících, takže den přiznání se z něj vyčíst nedá a pravidlo stálo
-- jen v nápovědě. NULL = den přiznání neznámý (starší záznam, import hlášení).
--
-- ── Žádost o potvrzení o zdanitelných příjmech (§ 38j odst. 3 ZDP) ───────────
-- Plátce vystaví potvrzení do 10 dnů od žádosti poplatníka kdykoli, nejen při
-- skončení vztahu. Dosud šla žádost zapsat jen k položce výstupního checklistu.
-- Tabulka drží každou žádost zvlášť (za rok i opakovaně), vyřízení se odvodí
-- z vystaveného potvrzení, nebo ho účetní potvrdí ručně.
--
-- ── Předložení dokladů k vyúčtování pracovní cesty (§ 183 ZP) ────────────────
-- Zaměstnanec předloží doklady do 10 pracovních dnů po skončení cesty,
-- zaměstnavatel vyúčtuje do 10 pracovních dnů od předložení. Den vyúčtování je
-- den schválení cesty (`approved_at`), chyběl den předložení dokladů.
--
-- IDEMPOTENCE: ADD COLUMN IF NOT EXISTS, CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE payroll_dependants
  ADD COLUMN IF NOT EXISTS ztp_p_granted_on DATE NULL
      COMMENT 'Den, od kterého byl přiznán průkaz ZTP/P (§ 35c odst. 7 a 10 ZDP)'
      AFTER ztp_p;

CREATE TABLE IF NOT EXISTS payroll_taxable_income_confirmation_requests (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id     INT UNSIGNED NOT NULL,
  employee_id     BIGINT UNSIGNED NOT NULL,
  employment_id   BIGINT UNSIGNED NULL,
  requested_on    DATE NOT NULL,
  income_year     SMALLINT UNSIGNED NOT NULL,
  due_on          DATE NOT NULL,
  completed_on    DATE NULL,
  completion_kind ENUM('document','manual') NULL,
  note            VARCHAR(500) NULL,
  row_version     INT UNSIGNED NOT NULL DEFAULT 1,
  created_by      BIGINT UNSIGNED NULL,
  completed_by    BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_payroll_tic_request_open (supplier_id, completed_on, due_on),
  KEY idx_payroll_tic_request_employee (supplier_id, employee_id, requested_on),
  CONSTRAINT fk_payroll_tic_request_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_tic_request_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_tic_request_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_tic_request_completed_by
    FOREIGN KEY (completed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_tic_request_due CHECK (due_on >= requested_on),
  CONSTRAINT chk_payroll_tic_request_completion CHECK (
    (completed_on IS NULL AND completion_kind IS NULL)
    OR (completed_on IS NOT NULL AND completion_kind IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_business_trips
  ADD COLUMN IF NOT EXISTS documents_submitted_on DATE NULL
      COMMENT 'Den předložení dokladů k vyúčtování (§ 183 odst. 1 ZP)'
      AFTER advance_settlement;
