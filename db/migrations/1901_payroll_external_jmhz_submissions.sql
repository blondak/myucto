-- Podání ČSSZ, která za firmu podal předchozí mzdový program (PAMICA) nebo která
-- účetní nahrála jako XML hlášení jiného programu.
--
-- PROČ NE `payroll_submissions`: tam žijí podání, která vyrobilo a odeslalo MyÚčto —
-- se zmrazeným artefaktem, povinností, ledgerem pokusů a triggery, které tohle
-- vynucují. Cizí podání nic z toho nemá; vešlo by se tam jen s vymyšlenou povinností
-- a vymyšleným kanálem. Tady je to KOPIE dokladu o tom, co předchozí program podal:
-- hlavička, GUIDy podání a formulářů, stav odeslání a úplný obsah po atributech
-- datového slovníku JMHZ.
--
-- K čemu slouží: příprava vlastního hlášení pozná měsíc, za který řádné podání už
-- odešlo (druhé řádné podání za totéž období ČSSZ zamítá), a přehled podání ukáže
-- převzatá podání včetně měsíce, který předchozí program neodeslal.
--
-- Obsah je citlivý (jména, rodná čísla dětí, OIČ, ID PPV, částky), proto leží
-- zapečetěný (`payload_ciphertext`, kontext řádku) jako všechny citlivé mzdové
-- hodnoty; `payload_sha256` je otisk obsahu pro poznání změny při opakovaném importu.
-- Opakovaný import téhož podání řádek přepíše, nezaloží druhý (`source_key`).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_external_jmhz_submissions (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL DEFAULT 'production',
  -- `pamica` = převod z datového souboru PAMICA, `jmhz_xml` = nahrané XML hlášení.
  source                ENUM('pamica','jmhz_xml') NOT NULL,
  -- Identita podání ve zdroji (PAMICA `MH:<ID>`, XML GUID podání s typem a balíkem).
  source_key            VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  document_kind         ENUM('monthly','registration') NOT NULL DEFAULT 'monthly',
  -- Rozhodné období měsíčního hlášení; registrace ho nemá.
  period                CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NULL,
  submission_type       CHAR(1) CHARACTER SET ascii COLLATE ascii_bin NULL,
  submission_guid       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  -- Podání, které tohle opravuje (u PAMICA `MH:<RefID>`).
  corrected_source_key  VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
  status                ENUM('sent','not_sent') NOT NULL,
  filled_at             DATETIME NULL,
  submitted_at          DATETIME NULL,
  accepted_at           DATETIME NULL,
  form_count            INT UNSIGNED NOT NULL DEFAULT 0,
  program               VARCHAR(100) NULL,
  file_name             VARCHAR(255) NULL,
  payload_ciphertext    LONGTEXT NOT NULL,
  payload_hash          BINARY(32) NOT NULL,
  payload_sha256        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  imported_by           BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_external_jmhz_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_external_jmhz_source (supplier_id, environment, source, source_key),
  KEY idx_payroll_external_jmhz_period (supplier_id, environment, document_kind, period),

  CONSTRAINT fk_payroll_external_jmhz_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_external_jmhz_importer
    FOREIGN KEY (imported_by) REFERENCES users (id) ON DELETE SET NULL,

  CONSTRAINT chk_payroll_external_jmhz_period
    CHECK (period IS NULL OR period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'),
  CONSTRAINT chk_payroll_external_jmhz_kind_period
    CHECK (document_kind = 'registration' OR period IS NOT NULL),
  CONSTRAINT chk_payroll_external_jmhz_type
    CHECK (submission_type IS NULL OR submission_type IN ('R','O','S')),
  CONSTRAINT chk_payroll_external_jmhz_guid
    CHECK (
      submission_guid IS NULL
      OR submission_guid REGEXP '^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$'
    ),
  CONSTRAINT chk_payroll_external_jmhz_payload
    CHECK (payload_sha256 REGEXP '^[0-9a-f]{64}$' AND CHAR_LENGTH(payload_ciphertext) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Formuláře osob (a věty registrací) převzatého podání s vazbou na vztah v MyÚčtu.
CREATE TABLE IF NOT EXISTS payroll_external_jmhz_submission_forms (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  submission_id         BIGINT UNSIGNED NOT NULL,
  position              INT UNSIGNED NOT NULL,
  form_guid             CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  -- Typ formuláře R/O/S u hlášení, druh věty u registrace (přihláška, odhláška…).
  form_type             VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
  -- Pracovní poměr ve zdroji (PAMICA `RefPomer`), podle kterého se formulář páruje.
  source_relation_ref   VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  employee_id           BIGINT UNSIGNED NULL,
  employment_id         BIGINT UNSIGNED NULL,
  payload_ciphertext    LONGTEXT NOT NULL,
  payload_hash          BINARY(32) NOT NULL,
  payload_sha256        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_external_jmhz_form_position (submission_id, position),
  KEY idx_payroll_external_jmhz_form_employment (supplier_id, employment_id),
  KEY idx_payroll_external_jmhz_form_supplier (supplier_id, submission_id),

  CONSTRAINT fk_payroll_external_jmhz_form_submission
    FOREIGN KEY (supplier_id, submission_id)
    REFERENCES payroll_external_jmhz_submissions (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_external_jmhz_form_employee
    FOREIGN KEY (employee_id) REFERENCES payroll_employees (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_external_jmhz_form_employment
    FOREIGN KEY (employment_id) REFERENCES payroll_employments (id) ON DELETE SET NULL,

  CONSTRAINT chk_payroll_external_jmhz_form_guid
    CHECK (
      form_guid IS NULL
      OR form_guid REGEXP '^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$'
    ),
  CONSTRAINT chk_payroll_external_jmhz_form_payload
    CHECK (payload_sha256 REGEXP '^[0-9a-f]{64}$' AND CHAR_LENGTH(payload_ciphertext) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
