-- MyUcto.cz - ELDP: důchodové údaje zaměstnance a kód D (sestavovač v4).
--
-- Evidenční list sestavený od téhle verze nese výslovně potvrzené důchodové
-- údaje (den dosažení důchodového věku, předčasný starobní důchod, první měsíc
-- výplaty starobního důchodu v plné výši, účast na pojištění v cizině):
--   * § 38 odst. 1 věta druhá zákona č. 582/1991 Sb. (od 1. 1. 2025) - za
--     poživatele starobního důchodu v plné výši se list nevede, měsíce od
--     začátku výplaty se z listu vypouštějí,
--   * číselník kódů ELDP - druhý znak „D" pro činnost po dovršení důchodového
--     věku nebo pro poživatele předčasného starobního důchodu.
-- Údaje leží v šifrovaném snapshotu listu, tabulka jen rozšiřuje povolenou
-- verzi sestavovače. Dřívější listy zůstávají beze změny.

SET NAMES utf8mb4;

ALTER TABLE payroll_eldp_statements
  DROP CONSTRAINT IF EXISTS chk_payroll_eldp_statement_builder;
ALTER TABLE payroll_eldp_statements
  ADD CONSTRAINT chk_payroll_eldp_statement_builder
    CHECK (builder_version IN (
      'eldp-annual-statement.v1',
      'eldp-annual-statement.v2',
      'eldp-annual-statement.v3',
      'eldp-annual-statement.v4'
    ));
