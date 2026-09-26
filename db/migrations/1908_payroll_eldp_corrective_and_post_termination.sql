-- MyUcto.cz - evidenční list: opravný list, příjem po skončení zaměstnání
-- a dohody s účastí na pojištění.
--
-- PROČ
--
-- 1. Opravný evidenční list. Zmrazený list se nepřepisuje a změněný podklad
--    vyžaduje opravné podání. Jedinečný klíč nad (vztah, rok) ale dovoloval
--    jediný list, takže služba uměla jen odmítnout („vyžaduje opravné
--    podání") a opravný list postavit nešlo. Nově má každý list pořadí
--    v rozsahu a opravný nese odkaz na list, který opravuje. Přijatá podání
--    jiných mzdových programů opravné listy (typ 51, 52) běžně obsahují.
--
-- 2. Příjem zúčtovaný po skončení zaměstnání (kód „1P+", „AP+") nenese dobu
--    pojištění. List, který obsahuje jen takový řádek (zaměstnání skončilo
--    v předchozím roce), má nula dnů pojištění; stejně tak list pracovního
--    poměru, jehož všechny měsíce jsou bez započitatelného příjmu (znak „X").
--    Kontrola „1 až 366 dnů" by oba odmítla.
--
-- 3. Řádek „P+" je sekce navíc, takže dvanáct sekcí už není horní mez.
--
-- 4. Verze sestavovače v3 přidává do snapshotu údaje tiskopisu (typ listu,
--    „zaměstnán od", datum vyhotovení, měsíce „X"). Starší neměnné snapshoty
--    v1 a v2 zůstávají platné a čitelné.

SET NAMES utf8mb4;

ALTER TABLE payroll_eldp_statements
  ADD COLUMN IF NOT EXISTS statement_sequence SMALLINT UNSIGNED NOT NULL DEFAULT 1
    AFTER statement_year;

ALTER TABLE payroll_eldp_statements
  ADD COLUMN IF NOT EXISTS corrects_statement_id BIGINT UNSIGNED NULL
    AFTER statement_sequence;

ALTER TABLE payroll_eldp_statements
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_eldp_statement_scope_sequence
    (supplier_id, environment, employment_id, statement_year, statement_sequence);

ALTER TABLE payroll_eldp_statements
  DROP INDEX IF EXISTS uq_payroll_eldp_statement_scope;

ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT fk_payroll_eldp_statement_corrects
    FOREIGN KEY IF NOT EXISTS (supplier_id, environment, corrects_statement_id)
    REFERENCES payroll_eldp_statements (supplier_id, environment, id)
    ON DELETE RESTRICT;

-- MariaDB neumí IF NOT EXISTS u CHECK, takže se každé omezení nejdřív zahodí.

ALTER TABLE payroll_eldp_statements
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_statement_correction;
ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT chk_payroll_eldp_statement_correction
    CHECK (
      (statement_sequence = 1 AND corrects_statement_id IS NULL)
      OR (statement_sequence > 1 AND corrects_statement_id IS NOT NULL)
    );

ALTER TABLE payroll_eldp_statements
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_statement_days;
ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT chk_payroll_eldp_statement_days
    CHECK (
      insurance_days BETWEEN 0 AND 366
      AND excluded_days_total <= insurance_days
      AND deducted_days_total <= insurance_days
    );

ALTER TABLE payroll_eldp_statements
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_statement_sections;
ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT chk_payroll_eldp_statement_sections
    CHECK (section_count BETWEEN 1 AND 24);

ALTER TABLE payroll_eldp_statements
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_statement_builder;
ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT chk_payroll_eldp_statement_builder
    CHECK (builder_version IN (
      'eldp-annual-statement.v1',
      'eldp-annual-statement.v2',
      'eldp-annual-statement.v3'
    ));
