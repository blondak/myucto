-- MyÚčto.cz: odložený příjem (JMHZ scénář 8, typ 10548).
--
-- Příjem zúčtovaný po skončení pracovního vztahu (typicky doplatek odměny)
-- se v měsíčním hlášení vykazuje samostatným formulářem „Odložený příjem"
-- navázaným na skončený vztah. Druh situace (10548) aplikace odhadnout
-- nesmí: pravidla podání pro něj mají šest typů s různým dopadem na pojistné
-- a ELDP. Účetní ho proto potvrdí za vztah a měsíc zúčtování; mzdový běh si
-- potvrzení zmrazí do vstupu a teprve pak příjem přiřadí k měsíci zúčtování.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_employment_deferred_incomes (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id     INT UNSIGNED NOT NULL,
  employment_id   BIGINT UNSIGNED NOT NULL,
  period_start    DATE NOT NULL,
  deferred_type   ENUM('1', '2', '3', '4', '5', '6') NOT NULL,
  note            VARCHAR(500) NULL,
  row_version     INT UNSIGNED NOT NULL DEFAULT 1,
  created_by      BIGINT UNSIGNED NULL,
  updated_by      BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                  ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_deferred_income_month (supplier_id, employment_id, period_start),
  UNIQUE KEY uq_payroll_deferred_income_id (supplier_id, id),
  CONSTRAINT fk_payroll_deferred_income_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_deferred_income_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_deferred_income_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_deferred_income_first_day
    CHECK (DAY(period_start) = 1)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
