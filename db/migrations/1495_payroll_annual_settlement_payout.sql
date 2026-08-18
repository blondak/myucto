-- ─────────────────────────────────────────────────────────────────────────────
-- Vrácení doplatku ze zúčtování ve mzdovém běhu (§ 38ch odst. 5, § 35d odst. 8)
-- ─────────────────────────────────────────────────────────────────────────────
-- Roční zúčtování dosud končilo dokladem. Peníze se nikam nepohnuly: sloupec
-- `payroll_input_id` z migrace 1399 počítal s tím, že se přeplatek vrátí jako
-- mzdový vstup, ale nikdy ho nikdo nezaložil a zůstával NULL.
--
-- Mzdovým vstupem to být nemůže. Vstup se vždycky promítne do úhrnu zúčtovaných
-- mezd, a doplatek ze zúčtování žádný příjem není — je to vrácení vlastní
-- zálohy. Kdyby prošel složkou, objevil by se na mzdovém listě v úhrnu mezd
-- (§ 38j odst. 2 písm. f bod 1), v základu pojistného i v jednotném měsíčním
-- hlášení. Proto se vrací jako samostatná položka čisté výplaty a vazba se drží
-- na revizi mzdového běhu, ve které se vyplatil.
--
-- Do základu srážek nevstupuje: § 277 odst. 1 OSŘ počítá čistou mzdu ze mzdy
-- snížené o zálohu na daň a pojistné, a vrácená záloha mzdou není.

ALTER TABLE payroll_net_results
  ADD COLUMN IF NOT EXISTS annual_settlement_minor BIGINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Doplatek ze zúčtování vyplacený v tomto běhu (§ 35d odst. 8).'
    AFTER correction_minor;

-- Mrtvá vazba na mzdový vstup. Odstraňuje se celá i s kontrolou a klíčem, aby
-- vedle sebe nestály dvě cesty, jak se přeplatek vrací.
ALTER TABLE payroll_annual_settlement_outcomes
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_outcome_input;
ALTER TABLE payroll_annual_settlement_outcomes
  DROP FOREIGN KEY IF EXISTS fk_payroll_annual_settlement_outcome_input;
ALTER TABLE payroll_annual_settlement_outcomes
  DROP INDEX IF EXISTS fk_payroll_annual_settlement_outcome_input;
ALTER TABLE payroll_annual_settlement_outcomes
  DROP COLUMN IF EXISTS payroll_input_id;

ALTER TABLE payroll_annual_settlement_outcomes
  ADD COLUMN IF NOT EXISTS payout_run_id BIGINT UNSIGNED NULL
    COMMENT 'Mzdový běh, ve kterém se doplatek ze zúčtování vyplatil.',
  ADD COLUMN IF NOT EXISTS payout_revision_id BIGINT UNSIGNED NULL
    COMMENT 'Revize toho běhu — při opravné revizi se vazba přepíše.',
  ADD COLUMN IF NOT EXISTS payout_period_start DATE NULL
    COMMENT 'Mzdové období výplaty. § 38ch odst. 5: nejpozději březen roku N+1.';

ALTER TABLE payroll_annual_settlement_outcomes
  ADD KEY IF NOT EXISTS fk_payroll_annual_settlement_outcome_payout_run
    (supplier_id, payout_run_id);
ALTER TABLE payroll_annual_settlement_outcomes
  ADD KEY IF NOT EXISTS fk_payroll_annual_settlement_outcome_payout_revision
    (supplier_id, payout_revision_id);

ALTER TABLE payroll_annual_settlement_outcomes
  DROP FOREIGN KEY IF EXISTS fk_payroll_annual_settlement_outcome_payout_run;
ALTER TABLE payroll_annual_settlement_outcomes
  ADD CONSTRAINT fk_payroll_annual_settlement_outcome_payout_run
    FOREIGN KEY (supplier_id, payout_run_id)
    REFERENCES payroll_runs (supplier_id, id) ON DELETE RESTRICT;

ALTER TABLE payroll_annual_settlement_outcomes
  DROP FOREIGN KEY IF EXISTS fk_payroll_annual_settlement_outcome_payout_revision;
ALTER TABLE payroll_annual_settlement_outcomes
  ADD CONSTRAINT fk_payroll_annual_settlement_outcome_payout_revision
    FOREIGN KEY (supplier_id, payout_revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT;

-- Vyplatit se smí jen to, co se podle § 38ch odst. 5 vyplácí, a vazba je buď
-- celá, nebo žádná — půlka údaje by znamenala, že se nedá zjistit, kde peníze
-- odešly.
ALTER TABLE payroll_annual_settlement_outcomes
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_outcome_payout;
ALTER TABLE payroll_annual_settlement_outcomes
  ADD CONSTRAINT chk_payroll_annual_settlement_outcome_payout
    CHECK (
      (payout_run_id IS NULL
        AND payout_revision_id IS NULL
        AND payout_period_start IS NULL)
      OR (payout_run_id IS NOT NULL
        AND payout_revision_id IS NOT NULL
        AND payout_period_start IS NOT NULL
        AND payable_minor > 0)
    );
