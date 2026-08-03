-- MyÚčto.cz — MZ-03: marker jednorázového převodu identifikátorů zaměstnavatele.
--
-- Opravná migrace pro vývojové instalace, které už spustily původní 1194 bez
-- markeru. Data znovu nekopíruje: pouze zabrání jejich obnovení při přímém
-- opakovaném spuštění idempotentní migrace 1194.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_data_migration_markers (
  migration_key VARCHAR(128) NOT NULL,
  completed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (migration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO payroll_data_migration_markers (migration_key)
VALUES ('1194_employer_payment_identifiers_v1');
