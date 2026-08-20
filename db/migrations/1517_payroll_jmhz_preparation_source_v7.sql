-- MZ-22-W05 — příprava JMHZ unese víc než jednu osobu s ordinary evidence.
--
-- Ordinary evidence se zmrazuje ZA PRACOVNÍ VZTAH (tabulka
-- `payroll_jmhz_ordinary_evidence_snapshots` je unikátní na
-- `(supplier_id, source_revision_id, employee_id, employment_id)`), ale příprava
-- uměla přijmout jen jednu evidenci na revizi a navíc vyžadovala, aby revize
-- měla právě jednu osobu s právě jedním vztahem. Firma s víc zaměstnanci proto
-- JMHZ podání s ordinary evidencí nezmrazila vůbec a víceúčtárenské podání
-- (revize přes dvě účtárny má vždy ≥2 vztahy) bylo z reálných dat nedosažitelné.
--
-- Verze v7 nese `ordinary_evidence` jako deterministicky seřazený SEZNAM
-- (jedna evidence na každý vztah revize) a chybějící evidence je adresný nález
-- na entitě `employment`, ne globální nález na revizi.

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
      'jmhz-preparation-source.v6',
      'jmhz-preparation-source.v7'
    )
  );
