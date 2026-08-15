-- Načtené protokoly ČSSZ o zpracování měsíčního hlášení.
--
-- PROČ VLASTNÍ TABULKA A NE `payroll_submission_transport_attempts` (1372):
-- ledger pokusů je důkaz o odeslání, které jsme udělali MY. Jeho `submission_id`
-- je NOT NULL cizí klíč do `payroll_submissions`, trigger navíc vynucuje, aby se
-- kanál pokusu shodoval s kanálem podání, a `idempotency_key_hash` i
-- `request_sha256` jsou povinné otisky NAŠEHO požadavku. Protokol, který přišel
-- do datové schránky k podání odeslanému cizím softwarem, nic z toho nemá —
-- vešel by se tam jen s vymyšleným podáním, vymyšleným kanálem a vymyšlenými
-- otisky, tedy jako smyšlený důkaz v append-only ledgeru. To je horší než
-- prázdná obrazovka. Navíc je ledger záměrně neměnný, kdežto opakované načtení
-- téhož protokolu se musí chovat idempotentně (přepsat, ne založit druhý řádek).
--
-- Řádek je KOPIE doručeného dokladu, ne stavová proměnná podání. Drží se proto
-- syrové XML: protokol má jednotky kilobajtů, je to jediná evidence o tom, co
-- ČSSZ skutečně poslala, a rozdělit ho na metadata v DB a soubor na disku by
-- znamenalo druhý životní cyklus (zálohy, retence, osiřelé odkazy) u dokladu,
-- který se vejde do řádku. Vedle je jeho SHA-256, aby šlo poznat pozdější zásah.
--
-- Variabilní symbol je NOT NULL schválně: bez něj se nedá ověřit, že protokol
-- patří téhle firmě, a neověřený protokol se do tabulky nesmí dostat vůbec.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_imported_jmhz_protocols (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  -- Druh protokolu tak, jak ho rozpoznal parser podle kořenového elementu.
  protocol_kind         ENUM('processing','completeness','partial_submission')
                          NOT NULL,
  -- Ověřený variabilní symbol zaměstnavatele; shoduje se s registračním číslem
  -- nebo s VS některého pracoviště, jinak se protokol vůbec neuloží.
  variable_symbol       VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  period_month          TINYINT UNSIGNED NULL,
  period_year           SMALLINT UNSIGNED NULL,
  -- `idPodani` — GUID, kterým se protokol páruje k podání. U protokolů, které
  -- ho nenesou, zůstává NULL a párovat jde jen přes correlation reference.
  submission_guid       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  correlation_reference VARCHAR(128) NULL,
  status_code           TINYINT UNSIGNED NOT NULL,
  -- Jméno případu výčtu JmhzSubmissionStatus (PascalCase) — frontend podle něj
  -- vybírá překlad, takže se nikdy nezobrazí syrový kód.
  status_name           VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  error_count           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- Časy tak, jak je napsala ČSSZ (ISO 8601 s offsetem) — nepřepočítávají se,
  -- aby se doklad nelišil od originálu.
  protocol_dated_at     VARCHAR(40) NULL,
  submitted_at          VARCHAR(40) NULL,
  source_filename       VARCHAR(255) NULL,
  payload_sha256        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payload_xml           MEDIUMTEXT NOT NULL,
  -- Otisk identity protokolu (GUID nebo correlation nebo obsah + období).
  -- Druhé načtení téhož souboru tak řádek přepíše místo zdvojení.
  dedupe_key            CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  imported_by           BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_imported_protocols_supplier_id
    (supplier_id, id),
  UNIQUE KEY uq_payroll_imported_protocols_environment_id
    (supplier_id, environment, id),
  UNIQUE KEY uq_payroll_imported_protocols_dedupe
    (supplier_id, environment, dedupe_key),
  KEY idx_payroll_imported_protocols_period
    (supplier_id, environment, period_year, period_month),
  KEY idx_payroll_imported_protocols_guid
    (supplier_id, environment, submission_guid),

  CONSTRAINT fk_payroll_imported_protocols_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_imported_protocols_importer
    FOREIGN KEY (imported_by) REFERENCES users (id) ON DELETE RESTRICT,

  CONSTRAINT chk_payroll_imported_protocols_vs
    CHECK (variable_symbol REGEXP '^[0-9]{1,10}$'),
  CONSTRAINT chk_payroll_imported_protocols_period
    CHECK (
      (period_month IS NULL OR (period_month BETWEEN 1 AND 12))
      AND (period_year IS NULL OR (period_year BETWEEN 2000 AND 2100))
    ),
  CONSTRAINT chk_payroll_imported_protocols_guid
    CHECK (
      submission_guid IS NULL
      OR submission_guid REGEXP
        '^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$'
    ),
  CONSTRAINT chk_payroll_imported_protocols_correlation
    CHECK (
      correlation_reference IS NULL
      OR correlation_reference REGEXP '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$'
    ),
  -- Šest doložených stavů měsíčního hlášení; sedmý by znamenal, že se stav
  -- někde dopočítal místo přečtení.
  CONSTRAINT chk_payroll_imported_protocols_status
    CHECK (status_code BETWEEN 1 AND 6),
  CONSTRAINT chk_payroll_imported_protocols_payload
    CHECK (
      payload_sha256 REGEXP '^[0-9a-f]{64}$'
      AND dedupe_key REGEXP '^[0-9a-f]{64}$'
      AND CHAR_LENGTH(payload_xml) > 0
    ),
  CONSTRAINT chk_payroll_imported_protocols_version
    CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

-- Identita načteného dokladu je neměnná. Opakované načtení smí zpřesnit stav
-- (protokol k témuž podání může přijít znovu), ale nesmí přepsat, KOMU doklad
-- patří ani za jaké období je — to by z evidence udělalo dohady.
DROP TRIGGER IF EXISTS trg_payroll_imported_protocols_update_guard//
CREATE TRIGGER trg_payroll_imported_protocols_update_guard
BEFORE UPDATE ON payroll_imported_jmhz_protocols
FOR EACH ROW
BEGIN
  IF NOT (NEW.supplier_id <=> OLD.supplier_id)
     OR NOT (NEW.environment <=> OLD.environment)
     OR NOT (NEW.variable_symbol <=> OLD.variable_symbol)
     OR NOT (NEW.dedupe_key <=> OLD.dedupe_key)
     OR NOT (NEW.period_year <=> OLD.period_year)
     OR NOT (NEW.period_month <=> OLD.period_month)
     OR NOT (NEW.created_at <=> OLD.created_at)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'imported protocol identity is immutable';
  END IF;

  IF NEW.row_version <> OLD.row_version + 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'imported protocol row_version must advance by one';
  END IF;
END//

DELIMITER ;
