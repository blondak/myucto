-- MyÚčto.cz — potvrzení od jiného plátce daně (§ 38ch odst. 3 ZDP).
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Proč tahle migrace je
-- ─────────────────────────────────────────────────────────────────────────────
-- Roční zúčtování (migrace 1399) umí posoudit, že poplatník doklady od
-- předchozích plátců PŘEDLOŽIL (`payroll_annual_settlement_requests.
-- prior_employers`), ale neumí je vzít do úhrnu — protože je nemá kam zapsat.
-- Důsledkem bylo, že se potvrzení nepoužilo vůbec: každé shodilo výpočet na
-- `external_certificate_incomplete`, a to i tehdy, když všechny údaje byly
-- k dispozici na papíře.
--
-- § 38ch odst. 4 přitom mluví o ÚHRNU mezd „všemi plátci postupně". Bez téhle
-- tabulky se zúčtování buď neprovede vůbec, nebo by se provedlo jen z části
-- roku — a to druhé je horší, protože z nižšího úhrnu záloh vyjde jiné číslo,
-- než jaké poplatníkovi náleží.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Co § 38ch odst. 3 po dokladu žádá — doslova
-- ─────────────────────────────────────────────────────────────────────────────
--   „Plátce daně provede roční zúčtování záloh a daňového zvýhodnění JEN na
--   základě dokladů za uplynulé zdaňovací období od všech předchozích plátců
--   daně o zúčtované nebo vyplacené mzdě, sražených zálohách na daň z těchto
--   příjmů, poskytnuté měsíční slevě na dani podle § 35ba a 35c a vyplacených
--   měsíčních daňových bonusech. Plátce daně roční zúčtování záloh a daňového
--   zvýhodnění neprovede, pokud poplatník tyto doklady nepředloží plátci daně
--   do 15. února po uplynutí zdaňovacího období."
--
-- Čtyři skupiny údajů, uzavřený výčet (váže na ně slovo „jen"), a slevy jsou
-- DVĚ samostatné položky — § 35ba a § 35c.
--
-- Podobu dokladu určuje § 38j odst. 3 („doklad o souhrnných údajích uvedených
-- ve mzdovém listě"), jeho obsah § 38j odst. 2 písm. f) a g). Tiskopisem je
-- 25 5460 MFin 5460 — vzor č. 33; sloupce níž na jeho řádky odkazují.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Proč jsou částky NULL, a ne NOT NULL DEFAULT 0
-- ─────────────────────────────────────────────────────────────────────────────
-- `NULL` znamená „na potvrzení to není", `0` znamená „je tam nula". Rozdíl je
-- peněžní: kdyby chybějící úhrn vyplacených bonusů spadl na nulu, porovnání
-- podle § 35d odst. 7 by se dělalo proti nižšímu úhrnu, rozdíl by vyšel kladný
-- a poplatník by dostal PODRUHÉ to, co už u předchozího plátce dostal.
-- Aplikace proto neúplné potvrzení nedopočítává — vrátí překážku
-- `external_certificate_incomplete` a zúčtování neprovede.
--
-- Z téhož důvodu tu NENÍ CHECK, který by vynucoval vyplnění všech částek:
-- rozpracované potvrzení musí jít uložit, jen se s ním nesmí počítat. Úplnost
-- posuzuje doména (`ExternalEmployerTaxCertificate::isComplete()`), protože je
-- to podmínka PROVEDENÍ úkonu, ne podmínka existence záznamu.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_annual_settlement_certificates (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                 INT UNSIGNED NOT NULL,
  employee_id                 BIGINT UNSIGNED NOT NULL,
  tax_year                    SMALLINT UNSIGNED NOT NULL,

  certificate_reference       VARCHAR(200) NOT NULL
                              COMMENT 'Označení dokladu — číslo potvrzení nebo jak je založené.',
  payer_name                  VARCHAR(255) NULL
                              COMMENT 'Předchozí plátce daně (jméno a adresa plátce z tiskopisu).',
  payer_tax_identification    VARCHAR(30) NULL
                              COMMENT 'DIČ plátce daně z tiskopisu.',
  received_on                 DATE NULL
                              COMMENT '§ 38ch odst. 3 věta druhá — po 15. 2. je doklad opožděný.',

  gross_income_minor          BIGINT UNSIGNED NULL
                              COMMENT 'ř. 1 — úhrn zúčtovaných příjmů (§ 38j odst. 2 písm. f) bod 1).',
  advance_base_minor          BIGINT UNSIGNED NULL
                              COMMENT 'ř. 5 — základ daně (§ 38j odst. 2 písm. f) bod 3).',
  advance_tax_minor           BIGINT UNSIGNED NULL
                              COMMENT 'ř. 8 — záloha na daň celkem, skutečně sražená (f) bod 7).',
  credit_35ba_minor           BIGINT UNSIGNED NULL
                              COMMENT 'Úhrn poskytnutých měsíčních slev podle § 35ba (f) bod 5).',
  credit_35c_minor            BIGINT UNSIGNED NULL
                              COMMENT 'Úhrn poskytnutých měsíčních slev podle § 35c (f) bod 6).',
  tax_bonus_minor             BIGINT UNSIGNED NULL
                              COMMENT 'ř. 9 — úhrn vyplacených měsíčních daňových bonusů (písm. g).',

  evidence_status             ENUM('unverified','verified')
                              NOT NULL DEFAULT 'unverified'
                              COMMENT '§ 38ch odst. 4 — do úhrnu smí jen doložené potvrzení.',
  evidence_reference          VARCHAR(500) NULL
                              COMMENT 'Čím je potvrzení doložené. U verified povinné.',

  note                        VARCHAR(1000) NULL,
  row_version                 INT UNSIGNED NOT NULL DEFAULT 1,
  created_by                  BIGINT UNSIGNED NULL,
  updated_by                  BIGINT UNSIGNED NULL,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_annual_settlement_certificate_supplier_id (supplier_id, id),
  -- Týž doklad se nesmí do úhrnu započítat dvakrát. Rozlišuje ho označení,
  -- protože předchozích plátců může být v roce víc.
  UNIQUE KEY uq_payroll_annual_settlement_certificate_scope
    (supplier_id, employee_id, tax_year, certificate_reference),
  KEY idx_payroll_annual_settlement_certificate_year
    (supplier_id, tax_year, employee_id),
  KEY fk_payroll_annual_settlement_certificate_creator (created_by),
  KEY fk_payroll_annual_settlement_certificate_editor (updated_by),

  CONSTRAINT fk_payroll_annual_settlement_certificate_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_annual_settlement_certificate_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_annual_settlement_certificate_editor
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Každé omezení zvlášť a vždy nejdřív zahodit: MariaDB neumí u CHECK ani
-- u cizího klíče `IF NOT EXISTS` a migrace se pouští opakovaně (testy
-- i čerstvý klon). Vzor je z migrací 1027, 1384, 1394 a 1399.
ALTER TABLE payroll_annual_settlement_certificates
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_certificate_year;
ALTER TABLE payroll_annual_settlement_certificates
  ADD CONSTRAINT chk_payroll_annual_settlement_certificate_year
    CHECK (tax_year BETWEEN 2000 AND 2199);

-- Označení dokladu nesmí být prázdné — je to jediné, čím se dva doklady od
-- dvou plátců od sebe odliší, a zároveň klíč jedinečnosti.
ALTER TABLE payroll_annual_settlement_certificates
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_certificate_reference;
ALTER TABLE payroll_annual_settlement_certificates
  ADD CONSTRAINT chk_payroll_annual_settlement_certificate_reference
    CHECK (certificate_reference <> '');

-- „Doloženo" bez doložení je jen tvrzení. § 38ch odst. 4 mluví o úhrnu mezd od
-- všech plátců — do toho úhrnu vstupuje doklad, ne nepodložený údaj.
ALTER TABLE payroll_annual_settlement_certificates
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_certificate_evidence;
ALTER TABLE payroll_annual_settlement_certificates
  ADD CONSTRAINT chk_payroll_annual_settlement_certificate_evidence
    CHECK (
      evidence_status <> 'verified'
      OR (evidence_reference IS NOT NULL AND evidence_reference <> '')
    );
