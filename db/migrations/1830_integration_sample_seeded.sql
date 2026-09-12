-- 1830: značka, že firma už dostala ukázkové napojení Integračního centra.
--
-- Samotné napojení zakládá aplikace (IntegrationSampleProvisioner) při prvním
-- otevření seznamu integrací uživatelem s právem na úpravu. Výchozí hodnoty
-- skládá IntegrationConnectionDefaults z definice konektoru a číselníků firmy,
-- SQL by je jen zdvojilo a nové firmy by nepokrylo. Značka zajistí, že se
-- smazaná ukázka už znovu nezaloží.
--
-- Re-run safe: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS integration_sample_seeded_at DATETIME NULL DEFAULT NULL
    COMMENT 'kdy aplikace založila ukázkové napojení Integračního centra';
