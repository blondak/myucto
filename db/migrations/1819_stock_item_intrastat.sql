-- Údaje zboží potřebné pro výkaz Intrastat / import do InstatEvo.

SET NAMES utf8mb4;

ALTER TABLE stock_items
  ADD COLUMN IF NOT EXISTS intrastat_cn8_code CHAR(8) NULL COMMENT 'osmimístný kód kombinované nomenklatury' AFTER weight_g,
  ADD COLUMN IF NOT EXISTS intrastat_country_of_origin CHAR(2) NULL COMMENT 'ISO 3166-1 alpha-2 země původu' AFTER intrastat_cn8_code,
  ADD COLUMN IF NOT EXISTS intrastat_net_mass_kg DECIMAL(14,3) NULL COMMENT 'čistá hmotnost jednoho kusu v kg' AFTER intrastat_country_of_origin,
  ADD COLUMN IF NOT EXISTS intrastat_supplementary_unit CHAR(3) NULL COMMENT 'doplňková měrná jednotka podle KN8' AFTER intrastat_net_mass_kg,
  ADD COLUMN IF NOT EXISTS intrastat_supplementary_unit_coefficient DECIMAL(18,6) NULL COMMENT 'počet doplňkových jednotek na jednu skladovou jednotku' AFTER intrastat_supplementary_unit;

ALTER TABLE stock_items
  ADD CONSTRAINT IF NOT EXISTS chk_stock_items_intrastat_cn8
    CHECK (intrastat_cn8_code IS NULL OR intrastat_cn8_code REGEXP '^[0-9]{8}$'),
  ADD CONSTRAINT IF NOT EXISTS chk_stock_items_intrastat_origin
    CHECK (intrastat_country_of_origin IS NULL OR intrastat_country_of_origin REGEXP '^[A-Z]{2}$'),
  ADD CONSTRAINT IF NOT EXISTS chk_stock_items_intrastat_net_mass
    CHECK (intrastat_net_mass_kg IS NULL OR intrastat_net_mass_kg > 0),
  ADD CONSTRAINT IF NOT EXISTS chk_stock_items_intrastat_supplementary
    CHECK (
      (intrastat_supplementary_unit IS NULL AND intrastat_supplementary_unit_coefficient IS NULL)
      OR (intrastat_supplementary_unit = 'ZZZ' AND intrastat_supplementary_unit_coefficient IS NULL)
      OR (
        intrastat_supplementary_unit REGEXP '^[A-Z0-9]{3}$'
        AND intrastat_supplementary_unit <> 'ZZZ'
        AND intrastat_supplementary_unit_coefficient > 0
      )
    );
