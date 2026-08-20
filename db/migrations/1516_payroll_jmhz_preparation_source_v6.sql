-- MZ-22-W04 — příprava JMHZ zná registrace u OSSZ za každou mzdovou účtárnu.
--
-- Přehled i měsíční hlášení se podávají za REGISTRACI, tedy za variabilní
-- symbol mzdové účtárny. Dosud si příprava brala variabilní symbol z účtárny
-- BĚHU (`payroll_runs.office_id`), což je jen filtr rozsahu běhu a u
-- celofiremního běhu je NULL — takový běh proto nešlo připravit vůbec.
-- Verze v6 nese `employer_summary.offices`, tedy registraci každé účtárny,
-- ze které se z revize podává.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_preparation_snapshots
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_preparation_builder;

ALTER TABLE payroll_jmhz_preparation_snapshots
  ADD CONSTRAINT chk_payroll_jmhz_preparation_builder CHECK (
    builder_version IN (
      'jmhz-preparation-source.v1',
      'jmhz-preparation-source.v2',
      'jmhz-preparation-source.v3',
      'jmhz-preparation-source.v4',
      'jmhz-preparation-source.v5',
      'jmhz-preparation-source.v6'
    )
  );
