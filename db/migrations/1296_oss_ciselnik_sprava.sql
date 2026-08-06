-- 1296: Uživatelská vrstva nad číselníkem sazeb členských států (OSS)
--
-- Migrace 1152 slíbila sloupcem `is_custom`, že si uživatel sazbu doplní nebo opraví,
-- ale nikdy pro to nevzniklo API ani UI — tabulka se od začátku jen ČETLA. Seed přitom
-- nevyhnutelně zestárne: stačí, aby kterýkoli z 27 států změnil sazbu, a doklad, na němž
-- je všechno v pořádku, dostane varování „sazba neodpovídá". Dosud to šlo spravit jedině
-- novou migrací a releasem.
--
-- ── Proč PŘEKRYVNÉ sloupce, a ne editace seedu ──────────────────────────────────────
-- Idempotence seedu stojí na `INSERT ... WHERE NOT EXISTS` nad čtveřicí
-- (country, rate_type, rate_percent, valid_from). Kdyby uživatel směl přepsat procento
-- nebo `valid_from` seedovaného řádku, přestala by čtveřice sedět a další spuštění
-- migrace by seed vložilo ZNOVU — vedle uživatelovy verze. K témuž datu by pak platily
-- dvě sazby téhož typu a `OssRateCodebook::checkRate()` by tiše přijala i tu zrušenou.
-- Opačným směrem hrozí totéž: migrace 1152 na konci uzavírá platnosti UPDATE příkazy,
-- takže by uživatelovu opravu přepsala.
--
-- Uživatelský zásah do seedovaného řádku proto nesahá na sloupce, které identita seedu
-- používá, ale ukládá se VEDLE:
--   * `valid_to_override` — zkrácení platnosti („stát sazbu změnil, seed ještě neví")
--   * `disabled_at`       — vyřazení řádku z ověřování („seed je prostě špatně")
-- Efektivní konec platnosti je `COALESCE(valid_to_override, valid_to)`. Seed si tak drží
-- vlastní data nedotčená a uživatelská vrstva přežije každé další spuštění migrací.
--
-- Vlastní (`is_custom = 1`) řádky uživatel edituje celé — ty žádný seed nevlastní, takže
-- kolize nehrozí; unikátní klíč `uq_osmr` hlídá, aby nešly založit dvakrát.
--
-- ── Proč se rozšíření platnosti neřeší ──────────────────────────────────────────────
-- `valid_to_override` umí platnost jen NAHRADIT, takže „seed má konec, ale ve skutečnosti
-- platí dál" se jím vyjádřit nedá (NULL znamená „žádný override", ne „bez konce").
-- Je to vědomé: prodloužení znamená, že seed je věcně jinde než realita, a tam patří nový
-- vlastní řádek s doloženou platností, ne tiché posunutí cizího údaje.

SET NAMES utf8mb4;

ALTER TABLE `oss_member_state_rates`
  ADD COLUMN IF NOT EXISTS `valid_to_override` DATE NULL
      COMMENT 'Uživatelské zkrácení platnosti seedu; efektivní konec = COALESCE(valid_to_override, valid_to)'
      AFTER `valid_to`,
  ADD COLUMN IF NOT EXISTS `disabled_at` DATETIME NULL
      COMMENT 'Uživatel řádek vyřadil z ověřování; NULL = řádek platí'
      AFTER `is_custom`,
  ADD COLUMN IF NOT EXISTS `created_by` INT UNSIGNED NULL AFTER `created_at`,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT NULL
      ON UPDATE CURRENT_TIMESTAMP AFTER `created_by`,
  ADD COLUMN IF NOT EXISTS `updated_by` INT UNSIGNED NULL AFTER `updated_at`;

-- Vyhledávací index musí vést i přes vyřazení, jinak by se `disabled_at IS NULL` dopočítávalo
-- až nad načtenými řádky. Původní `idx_osmr_lookup` zůstává — slouží dotazu bez data.
CREATE INDEX IF NOT EXISTS `idx_osmr_lookup_active`
  ON `oss_member_state_rates` (`country`, `disabled_at`, `valid_from`);
