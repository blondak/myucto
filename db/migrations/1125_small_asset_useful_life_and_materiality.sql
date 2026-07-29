-- EP-15: metodika drobného majetku / časového rozlišení.
-- Nová nepovinná pole (žádný backfill, žádná změna uzavřených let):
--   small_assets.useful_months = doložená doba použitelnosti (měsíce) pro pro_rata;
--     NULL = fallback na dosavadní proxy délkou období (chování beze změny).
--   accounting_periods.small_asset_flat_pct_materiality_limit = zdokumentovaný limit
--     významnosti báze 501.200 pro paušál (flat_pct); NULL = paušál není povolen
--     (nutí účetní politiku doložit). Paušál je tak jen politika pro nevýznamný
--     homogenní soubor s testem přiměřenosti, ne volné vyhlazení výsledku.

SET @@system_versioning_alter_history = 1;

ALTER TABLE small_assets
  ADD COLUMN useful_months SMALLINT UNSIGNED NULL
    COMMENT 'EP-15: doložená doba použitelnosti (měsíce) pro pro_rata; NULL = proxy délkou období'
    AFTER put_into_use_date;

ALTER TABLE accounting_periods
  ADD COLUMN small_asset_flat_pct_materiality_limit DECIMAL(14,2) NULL
    COMMENT 'EP-15: zdokumentovaný limit významnosti báze 501.200 pro flat_pct; NULL = paušál nepovolen';
