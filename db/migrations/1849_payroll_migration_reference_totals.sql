-- Úhrny mzdy převzaté z původního systému (PAMICA / POHODA Mzdy, Money S3).
--
-- PROČ: MyÚčto umí převzatý měsíc přepočítat vlastní legislativní sadou, jenže
-- původní systém už ta čísla podal do JMHZ, na zdravotní pojišťovny a na finanční
-- úřad. Bez uložené převzaté strany nemá účetní po přepočtu s čím porovnávat —
-- souhrnná čísla mzdy (`MZ` v exportu `91_mzdy.xml`) převod do teď nikam nezapisoval,
-- četl z nich jen podmnožinu pro počáteční stavy kumulací.
--
-- GRANULARITA: jeden řádek = jedna zpracovaná mzda původního systému, tedy
-- PRACOVNÍ VZTAH × MĚSÍC (`MZ.RefPomer` + `Rok`/`RelMes`). Kontrolní sestava
-- z nich sčítá osobu a měsíc, protože daň i pojistné jsou na naší straně veličiny
-- osoby, ne vztahu.
--
-- SOFT LINK na osobu a vztah: `employee_id` / `employment_id` jsou BEZ cizího klíče.
-- Řádek musí jít uložit i pro vztah, který se do MyÚčta nepřevedl (právě takový
-- rozdíl má sestava ukázat jako „nemá protějšek"), a nesmí blokovat výmaz osoby
-- podle retenčních pravidel. Identita z původního systému v `external_*_ref`
-- zůstane i tak, takže se řádek pozná a případně dopáruje později.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS + ADD COLUMN/INDEX IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_migration_reference_totals (
  id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id               INT UNSIGNED NOT NULL,
  source                    ENUM('pamica','pohoda','money_s3') NOT NULL,
  period_start              DATE NOT NULL COMMENT 'první den mzdového měsíce',
  external_person_ref       VARCHAR(64) COLLATE utf8mb4_bin NOT NULL COMMENT 'MZ.RefZAM',
  external_relationship_ref VARCHAR(64) COLLATE utf8mb4_bin NOT NULL COMMENT 'MZ.RefPomer',
  employee_id               BIGINT UNSIGNED NULL COMMENT 'soft link, NULL = vztah se nepřevedl',
  employment_id             BIGINT UNSIGNED NULL COMMENT 'soft link, NULL = vztah se nepřevedl',
  gross_minor               BIGINT NOT NULL DEFAULT 0 COMMENT 'MZ.KcHrubaM',
  net_minor                 BIGINT NOT NULL DEFAULT 0 COMMENT 'MZ.KcCistaM, před srážkami',
  social_base_minor         BIGINT NOT NULL DEFAULT 0 COMMENT 'MZ.KcSocZak',
  health_base_minor         BIGINT NOT NULL DEFAULT 0 COMMENT 'MZ.KcZdrZak',
  employee_social_minor     BIGINT NOT NULL DEFAULT 0 COMMENT 'MZ.KcSocL',
  employee_health_minor     BIGINT NOT NULL DEFAULT 0 COMMENT 'MZ.KcZdrL',
  employer_social_minor     BIGINT NOT NULL DEFAULT 0 COMMENT 'MZ.KcSoc',
  employer_health_minor     BIGINT NOT NULL DEFAULT 0 COMMENT 'MZ.KcZdr',
  advance_tax_minor         BIGINT NOT NULL DEFAULT 0 COMMENT 'MZ.KcZalDan',
  withholding_tax_minor     BIGINT NOT NULL DEFAULT 0 COMMENT 'MZ.KcSraDan',
  tax_bonus_minor           BIGINT NOT NULL DEFAULT 0 COMMENT 'MZ.KcDanBon',
  import_reference          VARCHAR(190) NULL COMMENT 'otisk nebo název exportu, ze kterého řádek vznikl',
  created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_pmrt_relationship_period
    (supplier_id, source, period_start, external_relationship_ref),
  KEY ix_pmrt_period (supplier_id, period_start),
  KEY ix_pmrt_employee (supplier_id, employee_id, period_start),
  CONSTRAINT fk_pmrt_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT chk_pmrt_period_first_day CHECK (DAY(period_start) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_migration_reference_totals
  ADD COLUMN IF NOT EXISTS import_reference VARCHAR(190) NULL AFTER tax_bonus_minor;

ALTER TABLE payroll_migration_reference_totals
  ADD INDEX IF NOT EXISTS ix_pmrt_employee (supplier_id, employee_id, period_start);
