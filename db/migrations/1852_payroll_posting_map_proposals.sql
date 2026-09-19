-- Návrh mzdových předkontací odvozený z převzatého zaúčtování (PAM-16).
--
-- PROČ: zákazník, který přechází z jiného mzdového programu, má kontace mezd
-- nastavené tam a export je nese (PAMICA `MZzauct` + číselník předkontací `pPK`).
-- Bez tohohle podkladu je účetní musí v MyÚčtu naklikat znovu a jediná záměna
-- analytiky pojistného se pozná až u odvodu.
--
-- CO SE NEPŘENÁŠÍ: samotné účetní zápisy. Mzdy zaúčtuje MyÚčto vlastní cestou
-- podle svého nastavení; převzaté zápisy by proti převedeným dokladům vyrobily
-- duplicitu. Tabulka proto drží JEN návrh nastavení, nic z ní se neúčtuje.
--
-- GRANULARITA: jeden řádek = jedna firma × jeden zdroj. Opakovaný převod téhož
-- exportu návrh přepíše (ON DUPLICATE KEY), nezaloží druhý. Potvrzený návrh se
-- nemaže - zůstává jako doklad o tom, z čeho nastavení vzniklo.
--
-- Nastavení samo se odsud NEPÍŠE: potvrzení jde přes běžnou cestu nastavení
-- zaměstnavatele (PayrollEmployerSettingsValidator + …Repository::save), takže
-- projde kontrolou osnovy, typu účtu i kolizních prefixů.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS. CHECK omezení jsou jen uvnitř něj -
-- MariaDB neumí ADD CONSTRAINT IF NOT EXISTS u CHECK, takže opakované spuštění
-- je no-op.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_posting_map_proposals (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id       INT UNSIGNED NOT NULL,
  -- `other` je obecný zdroj: převzaté zaúčtování není vázané na PAMICU, ta je
  -- jen první program s vlastním feederem. Sada musí zůstat v souladu s
  -- `PayrollPostingMapProposalStore::SOURCES` a s ENUM v migraci 1851.
  source            ENUM('pamica','pohoda','money_s3','other') NOT NULL,
  status            ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
  source_year       SMALLINT UNSIGNED NULL COMMENT 'rok exportu, ze kterého návrh vznikl',
  source_reference  VARCHAR(190) NULL COMMENT 'název nebo otisk exportu',
  proposal_json     LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
                    COMMENT 'odvozené účty i doklad k nim (PayrollPostingMapProposalBuilder)',
  confirmed_json    LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
                    COMMENT 'předkontace => účet, jak je účetní potvrdila',
  confirmed_at      TIMESTAMP NULL DEFAULT NULL,
  confirmed_by      INT UNSIGNED NULL COMMENT 'soft link na uživatele; smazaný účet nesmí blokovat výmaz',
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_ppmp_supplier_source (supplier_id, source),
  KEY ix_ppmp_status (supplier_id, status),
  CONSTRAINT fk_ppmp_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT chk_ppmp_proposal_json
    CHECK (JSON_VALID(proposal_json)),
  CONSTRAINT chk_ppmp_confirmed_json
    CHECK (confirmed_json IS NULL OR JSON_VALID(confirmed_json)),
  CONSTRAINT chk_ppmp_confirmed_pair
    CHECK ((status = 'confirmed') = (confirmed_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
