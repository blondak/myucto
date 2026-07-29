-- MyÚčto.cz — Epic DP v2 (issue #19), Fáze 2: opravná a dodatečná přiznání.
--
-- Řádné přiznání drží dnes UNIQUE (supplier_id, year, taxpayer_type) — jeden záznam
-- na období. Opravné (§ podané před lhůtou, plná náhrada) a dodatečné (§141 DŘ, po
-- lhůtě, rozdílově) přiznání jsou DALŠÍ podání za TOTÉŽ období → musí koexistovat
-- vedle řádného. Přidáváme rozlišovací sloupec `variant` a rozšiřujeme UNIQUE.
--
-- Ostatní vstupy dodatečného přiznání (poslední známá daň, datum zjištění, důvody)
-- jdou do stávajícího `inputs` JSON — stejně jako všechny ruční vstupy — bez dalších
-- sloupců. Snapshot výpočtu (computed) je per-varianta, takže „poslední známá daň"
-- se čte z předchozího finalizovaného řádného/opravného záznamu.
--
-- Aditivní, idempotentní (ADD COLUMN IF NOT EXISTS / DROP INDEX IF EXISTS /
-- CREATE UNIQUE INDEX IF NOT EXISTS). MyÚčto migrace od 1000_.

SET NAMES utf8mb4;

-- 1. Rozlišovací sloupec druhu přiznání. Default 'radne' → BC pro existující řádky.
ALTER TABLE income_tax_returns
  ADD COLUMN IF NOT EXISTS variant ENUM('radne','opravne','dodatecne')
    NOT NULL DEFAULT 'radne'
    COMMENT 'druh přiznání: řádné / opravné (před lhůtou) / dodatečné (§141 DŘ, po lhůtě)'
    AFTER taxpayer_type;

-- 2. Rozšíření unikátního klíče o variantu (jedno řádné + jedno opravné + jedno
--    dodatečné za období). Staré uq_itr (supplier,year,type) se nahrazuje.
ALTER TABLE income_tax_returns DROP INDEX IF EXISTS uq_itr;
CREATE UNIQUE INDEX IF NOT EXISTS uq_itr_variant
  ON income_tax_returns (supplier_id, year, taxpayer_type, variant);
