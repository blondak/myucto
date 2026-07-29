-- MyÚčto.cz — Epic DP v2 (issue #19), Fáze 3: e-podání ČSSZ (přehled OSVČ).
--
-- ČSSZ přehled OSVČ (XML e-podání, schéma osvc25.xsd) potřebuje identifikátory,
-- které nejsou pro daňové přiznání (EPO MFČR): variabilní symbol OSVČ pro ČSSZ
-- (`vsdp`, 8 číslic) a kód místně příslušné OSSZ (`dep`, 3 číslice, číselník okresů).
-- Číslo pojištěnce zdravotní pojišťovny se připravuje pro budoucí ZP e-podání a
-- do PDF přehledu (ZP zatím jen PDF pomůcka — nemá jednotné veřejné schéma).
--
-- Rodné číslo (`bno`) a datum narození (`den`) přehledu se odvozují z DIČ FO
-- (kmenová část = RČ) — samostatné sloupce nezavádíme. Aditivní, idempotentní.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS cssz_vsdp VARCHAR(20) NULL
    COMMENT 'variabilní symbol OSVČ pro ČSSZ (přehled OSVČ, e-podání) — číselný VS',
  ADD COLUMN IF NOT EXISTS cssz_ossz_code VARCHAR(3) NULL
    COMMENT 'kód místně příslušné OSSZ (okres) pro přehled OSVČ ČSSZ (dep)',
  ADD COLUMN IF NOT EXISTS health_insurance_number VARCHAR(20) NULL
    COMMENT 'číslo pojištěnce zdravotní pojišťovny (ZP přehled — zatím PDF, XML v přípravě)';
