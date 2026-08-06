-- MyÚčto.cz — MZ-02-W07: admin-editovatelné legislativní rulesety mezd.
--
-- Stejný vzor jako číselník daňových konstant (migrace 0079): kód drží jedinou
-- ověřenou sadu (CzechPayrollRulesets2026 = default i fallback), do DB se ukládá
-- POUZE override. Chybí-li řádek, platí default z kódu; reset = smazání řádku.
--
-- Globální (NE per-supplier) — mzdové sazby, hranice a lhůty jsou národní,
-- stejně jako daňové konstanty. Bez seedu: re-run migrace nesmí přepsat editace.
--
-- Merge je per klíč: override nese jen změněné parametry a jen ty sloupce
-- účinnosti/stavu, které se skutečně mění. NULL sloupec = „dědím z kódu",
-- takže override uložený starší verzí aplikace neztratí později přidané klíče.
--
-- Idempotence: MariaDB-native IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_rulesets (
  ruleset_id      VARCHAR(160) NOT NULL PRIMARY KEY
                    COMMENT 'Identita verze, shodná s klíčem defaultu v kódu',
  domain          VARCHAR(32) NOT NULL,
  version         VARCHAR(64) NULL COMMENT 'NULL = dědí z kódu',
  effective_from  DATE NULL COMMENT 'NULL = dědí z kódu',
  effective_to    DATE NULL COMMENT 'NULL = dědí z kódu',
  lifecycle       VARCHAR(16) NULL COMMENT 'NULL = dědí z kódu',
  capability      VARCHAR(16) NULL COMMENT 'NULL = dědí z kódu',
  data            JSON NOT NULL
                    COMMENT 'Override obsahu: {"parameters":{...},"sources":[...]}',
  content_hash    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
                    COMMENT 'SHA-256 kanonické podoby EFEKTIVNÍHO obsahu při uložení',
  reason          VARCHAR(1000) NOT NULL COMMENT 'Důvod poslední změny',
  created_by      INT UNSIGNED NULL,
  updated_by      INT UNSIGNED NULL,
  reviewed_by     INT UNSIGNED NULL,
  reviewed_at     TIMESTAMP NULL DEFAULT NULL,
  approved_by     INT UNSIGNED NULL,
  approved_at     TIMESTAMP NULL DEFAULT NULL,
  activated_by    INT UNSIGNED NULL,
  activated_at    TIMESTAMP NULL DEFAULT NULL,
  superseded_by   INT UNSIGNED NULL,
  superseded_at   TIMESTAMP NULL DEFAULT NULL,
  row_version     INT UNSIGNED NOT NULL DEFAULT 1,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_payroll_ruleset_domain (domain, effective_from, effective_to),
  -- Výčet domén tu záměrně NENÍ jako CHECK: seznam se rozšiřuje spolu s enumem
  -- PayrollRulesetDomain v kódu a schéma by se s ním rozcházelo. Hodnotu validuje
  -- aplikace proti enumu (fail-closed), stejně jako u ostatních číselníků.
  CONSTRAINT chk_payroll_ruleset_lifecycle
    CHECK (lifecycle IS NULL OR lifecycle IN
      ('draft', 'reviewed', 'approved', 'active', 'superseded')),
  CONSTRAINT chk_payroll_ruleset_capability
    CHECK (capability IS NULL OR capability IN ('supported', 'manual_review')),
  CONSTRAINT chk_payroll_ruleset_effective
    CHECK (effective_from IS NULL OR effective_to IS NULL
           OR effective_to >= effective_from),
  CONSTRAINT chk_payroll_ruleset_data CHECK (JSON_VALID(data)),
  CONSTRAINT chk_payroll_ruleset_hash
    CHECK (content_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_ruleset_reason CHECK (CHAR_LENGTH(reason) > 0),
  CONSTRAINT chk_payroll_ruleset_row_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only stopa: kdo, kdy, co a proč změnil. Záměrně BEZ cizího klíče na
-- payroll_rulesets — reset override řádek maže a auditní záznam o tom, že se
-- resetoval, musí přežít.
CREATE TABLE IF NOT EXISTS payroll_ruleset_audit (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ruleset_id     VARCHAR(160) NOT NULL,
  domain         VARCHAR(32) NOT NULL,
  action         VARCHAR(16) NOT NULL,
  reason         VARCHAR(1000) NOT NULL,
  snapshot_json  JSON NOT NULL COMMENT 'Kanonický efektivní obsah po zásahu',
  snapshot_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  previous_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  lifecycle      VARCHAR(16) NOT NULL,
  actor_user_id  INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_payroll_ruleset_audit_ruleset (ruleset_id, id),
  CONSTRAINT chk_payroll_ruleset_audit_action
    CHECK (action IN ('created', 'updated', 'reset', 'review', 'approve',
                      'activate', 'supersede')),
  CONSTRAINT chk_payroll_ruleset_audit_reason CHECK (CHAR_LENGTH(reason) > 0),
  CONSTRAINT chk_payroll_ruleset_audit_json CHECK (JSON_VALID(snapshot_json)),
  CONSTRAINT chk_payroll_ruleset_audit_hash
    CHECK (snapshot_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_ruleset_update//

CREATE TRIGGER trg_payroll_ruleset_update
BEFORE UPDATE ON payroll_rulesets
FOR EACH ROW
BEGIN
  IF NEW.ruleset_id <> OLD.ruleset_id OR NEW.domain <> OLD.domain THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll ruleset identity is immutable';
  END IF;

  IF NEW.row_version <= OLD.row_version THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll ruleset row version must increase';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_ruleset_audit_update//

CREATE TRIGGER trg_payroll_ruleset_audit_update
BEFORE UPDATE ON payroll_ruleset_audit
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll ruleset audit is append-only';
END//

DROP TRIGGER IF EXISTS trg_payroll_ruleset_audit_delete//

CREATE TRIGGER trg_payroll_ruleset_audit_delete
BEFORE DELETE ON payroll_ruleset_audit
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll ruleset audit is append-only';
END//

DELIMITER ;
