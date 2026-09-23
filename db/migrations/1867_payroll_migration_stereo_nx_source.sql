-- Samostatný zdroj historických mezd ze Stereo NX.
--
-- `other` zůstává vyhrazený pro starší převody PREMIER a obecný tabulkový
-- import. Oddělená hodnota je nutná, aby se přehled převzatých mezd a
-- reconciliation nemíchaly mezi dvěma původními systémy.

SET NAMES utf8mb4;

ALTER TABLE payroll_migration_reference_totals
  MODIFY COLUMN source ENUM('pamica','pohoda','money_s3','other','stereo_nx') NOT NULL;
