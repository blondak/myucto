-- MyÚčto.cz — kód podepisující osoby (zast_kod) v evidenci zastoupení (#124).
--
-- Migrace 1662 evidovala jen zastoupení daňovým poradcem/advokátem a kód odvozovala
-- z typu zástupce (F → 4b, P → 4c). Číselník EPO má ale dvanáct typů podepisující
-- osoby: zákonný zástupce, ustanovený a společný zástupce, obecný zmocněnec (4a,
-- typicky účetní kancelář bez osvědčení daňového poradce), správce pozůstalosti,
-- dědic a právní nástupce. Kód je teď uživatelský vstup.
--
-- Na kódu závisí víc než atribut zast_kod:
--   * dan_por (DPPO) / pln_moc (DPFO) = A jen u daňového poradce (4b, 4c);
--   * prodloužená lhůta § 136 odst. 2 DŘ platí jen u daňového poradce nebo advokáta;
--   * evidenční číslo existuje jen u poradce; ostatní fyzické osoby EPO identifikuje
--     datem narození (XSD: „buď datum narození, nebo evidenční číslo je povinné").
--
-- Zástupce právnická osoba podepisuje přes konkrétní fyzickou osobu. XSD ji vede
-- v opr_* („vyplňuje se, je-li typ … jeho podepisující osoby právnická osoba"),
-- takže se k řádku eviduje i ona (representative_signer_*).
--
-- Podmínku „evidenční číslo, nebo datum narození" hlídá API, ne CHECK: je to
-- požadavek EPO na podání, ne na tvar záznamu, a CHECK u něj necháváme volnější.
SET NAMES utf8mb4;

ALTER TABLE supplier_tax_representation_history
  ADD COLUMN IF NOT EXISTS representative_code ENUM('1','2','3','4a','4b','4c','5a','5b','6a','6b','7a','7b') NULL
    COMMENT 'zast_kod — kód podepisující osoby podle číselníku EPO' AFTER representative_type,
  ADD COLUMN IF NOT EXISTS representative_birth_date DATE NULL
    COMMENT 'zast_dat_nar — u fyzické osoby bez evidenčního čísla (kromě 4b)' AFTER representative_ev_number,
  ADD COLUMN IF NOT EXISTS representative_signer_first_name VARCHAR(20) NULL
    COMMENT 'opr_jmeno — osoba podepisující za zástupce právnickou osobu' AFTER representative_birth_date,
  ADD COLUMN IF NOT EXISTS representative_signer_last_name VARCHAR(36) NULL
    COMMENT 'opr_prijmeni — osoba podepisující za zástupce právnickou osobu' AFTER representative_signer_first_name,
  ADD COLUMN IF NOT EXISTS representative_signer_position VARCHAR(40) NULL
    COMMENT 'opr_postaveni — vztah podepisující osoby k zástupci právnické osobě' AFTER representative_signer_last_name;

ALTER TABLE supplier_tax_representation_history
  MODIFY COLUMN representative_ev_number VARCHAR(36) NULL
    COMMENT 'zast_ev_cislo — evidenční číslo v seznamu KDP ČR/ČAK, povinné u kódu 4b';

UPDATE supplier_tax_representation_history
   SET representative_code = IF(representative_type = 'P', '4c', '4b')
 WHERE represented = 1 AND representative_code IS NULL;

ALTER TABLE supplier_tax_representation_history
  DROP CONSTRAINT IF EXISTS chk_supplier_tax_representation_identity;

ALTER TABLE supplier_tax_representation_history
  ADD CONSTRAINT chk_supplier_tax_representation_identity CHECK (
    represented = 0
    OR (
      representative_type IS NOT NULL
      AND representative_code IS NOT NULL
      AND (
        (
          representative_type = 'F'
          AND representative_code IN ('1','2','3','4a','4b','5a','5b','6a','6b','7b')
          AND representative_first_name IS NOT NULL
          AND representative_last_name IS NOT NULL
          AND (representative_code <> '4b' OR representative_ev_number IS NOT NULL)
        )
        OR (
          representative_type = 'P'
          AND representative_code IN ('1','2','3','4a','4c','5a','5b','6a','6b','7a','7b')
          AND representative_company_name IS NOT NULL
        )
      )
    )
  );
