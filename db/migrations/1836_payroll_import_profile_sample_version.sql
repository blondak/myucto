-- MyÚčto.cz — Mzdy → Importy: verze ukázkového profilu (Vzor GIRITON).
--
-- Ukázkový profil se firmě zakládá jednou (migrace 1835), takže firma se
-- starším vzorem by nová pravidla nikdy nedostala. `sample_version` = verze
-- vzoru z kódu, ze které profil vznikl; `sample_rules_sha256` = otisk
-- pravidel a složek v té podobě, v jaké je aplikace zapsala. Sedí-li otisk
-- s uloženým obsahem, profil nikdo neupravil a aplikace ho smí nahradit
-- novější verzí vzoru. Upravený profil se nepřepisuje.
--
-- Doplnění stávajících vzorů: nedotčený vzor (row_version = 1, uložení
-- profilu verzi vždy zvyšuje) dostane verzi 1 a otisk uloženého obsahu.
-- Otisk = SHA-256 z `rules_json` + LF + `components_json`, stejně jako
-- PayrollImportProfileRepository::contentHash().
--
-- Re-run safe: ADD COLUMN IF NOT EXISTS, doplnění jen tam, kde otisk chybí.

SET NAMES utf8mb4;

ALTER TABLE payroll_import_profiles
  ADD COLUMN IF NOT EXISTS sample_version INT NULL AFTER is_sample,
  ADD COLUMN IF NOT EXISTS sample_rules_sha256 CHAR(64) NULL AFTER sample_version;

UPDATE payroll_import_profiles
   SET sample_version = 1,
       sample_rules_sha256 = SHA2(CONCAT(rules_json, CHAR(10), COALESCE(components_json, '')), 256)
 WHERE is_sample = 1
   AND row_version = 1
   AND sample_rules_sha256 IS NULL;
