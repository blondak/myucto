-- MyÚčto.cz — Zálohy §38a dle REALITY: rozhodnutí FÚ o změně výše záloh (§174 DŘ) + ruční potvrzení
--
-- Issues #43 (rozhodnutí FÚ o změně výše záloh) a #42 (náhled bez finalizace min. roku).
--
-- PROBLÉM: předpisy záloh na daň §38a vznikají z PREDIKCE (poslední známá daň) a #39
-- páruje reálnou platbu jen v pásmu ±30 % kolem predikce. Když ale FÚ na žádost dle
-- §174 DŘ SNÍŽÍ (nebo změní) zálohy — např. z predikovaných čtvrtletních ~150 000 na
-- 85 000 — reálná platba pak leží mimo pásmo kolem predikce a #39 ji
-- odmítne. To je špatně: skutečná výše zálohy je daná rozhodnutím FÚ, ne predikcí.
--
-- ŘEŠENÍ — durable override rozhodnutím FÚ (tax_advance_overrides):
--   * uchovává NOVOU výši zálohy, periodicitu a datum účinnosti (effective_from);
--   * generování předpisů (TaxAdvanceScheduleService::buildTaxRows) ho konzultuje a od
--     účinnosti používá override částku a periodicitu MÍSTO predikce;
--   * párování #39 pak počítá toleranci proti EFEKTIVNÍ (override) částce na předpisu,
--     takže skutečná (snížená) záloha se napáruje jako 'exact', kdežto doplatek daně min. roku
--     nebo cizí platba se stejným VS = DIČ dál padnou mimo pásmo a NEspárují (ochrana #39
--     zachována — počítá se proti override částce, ne proti násobkům).
--   * override žije NEZÁVISLE na finalizaci přiznání min. roku → řeší i #42: předpisy na
--     rok Y lze vygenerovat/párovat i bez finalizace řádného přiznání roku Y-1 (buď z jeho
--     draftu dle ř. 340, nebo čistě z override, když FÚ zálohy stanovil).
--
-- Durable proto, že replacePlanned() při každé regeneraci maže 'planned' předpisy —
-- editace holé částky na předpisu by se ztratila; override je zdroj pravdy, který
-- generování čte znovu. Ruční per-předpis úpravy (níže) zůstávají jako ad-hoc doladění.
--
-- paid_source na tax_advance_schedules: odliší RUČNĚ potvrzenou úhradu ('manual', bez
-- bankovní transakce — účetní zná úhradu, kterou modul netrackuje, i hromadné „vše
-- zaplaceno") od bankou spárované ('bank'). Ruční potvrzení je 'exact' (potvrdila účetní),
-- takže vstupuje do automatického součtu zaplacených záloh v přiznání.
--
-- Idempotence: IF NOT EXISTS všude. Tenant izolace přes supplier_id (FK ON DELETE CASCADE).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tax_advance_overrides (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id     INT UNSIGNED NOT NULL,
    taxpayer_type   ENUM('fo','po') NOT NULL DEFAULT 'po',
    advance_kind    ENUM('tax','social','health') NOT NULL DEFAULT 'tax' COMMENT 'druh zálohy, jehož výši rozhodnutí mění (v1: jen daň §38a)',
    period_year     SMALLINT UNSIGNED NOT NULL COMMENT 'rok, za který se zálohy platí (= rok příštího přiznání)',
    effective_from  DATE NOT NULL COMMENT 'datum účinnosti rozhodnutí — od něj se předpisy počítají na override výši',
    amount          DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'nová výše jedné zálohy dle rozhodnutí FÚ / ruční',
    periodicity     ENUM('quarterly','semiannual','annual','none') NOT NULL DEFAULT 'quarterly' COMMENT 'periodicita záloh dle rozhodnutí',
    note            VARCHAR(255) NULL COMMENT 'poznámka (č. j. rozhodnutí FÚ, důvod)',
    source          ENUM('fu_decision','manual') NOT NULL DEFAULT 'fu_decision' COMMENT 'rozhodnutí FÚ §174 vs ruční override účetní',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_tao (supplier_id, taxpayer_type, advance_kind, period_year, effective_from),
    KEY idx_tao_lookup (supplier_id, taxpayer_type, advance_kind, period_year),
    CONSTRAINT fk_tao_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tax_advance_schedules
    ADD COLUMN IF NOT EXISTS paid_source ENUM('bank','manual') NOT NULL DEFAULT 'bank'
        COMMENT 'zdroj úhrady: bank = spárováno s bankovní transakcí, manual = ručně potvrzeno účetní (bez transakce)'
        AFTER match_confidence;
