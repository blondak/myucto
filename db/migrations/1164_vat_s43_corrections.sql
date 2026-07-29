-- 1164: § 43 ZDPH — oprava výše daně v jiných případech (per doklad)
--
-- Systém uměl dodatečné přiznání jako CELEK, ale neměl institut opravy per doklad ani
-- vazbu na období původního plnění. Účetní tak musela rozdíl dopočítat ručně mimo systém
-- a nikde nezůstala stopa, ČEHO se oprava týkala — což je přesně to, co správce daně
-- při kontrole chce vidět.
--
-- ── Čím se § 43 liší od § 42 ────────────────────────────────────────────────────────
-- § 42 je oprava ZÁKLADU daně (dobropis, sleva, vrácení plnění) a patří do období
-- DORUČENÍ opravného dokladu — tedy dopředu. § 43 je oprava VÝŠE daně, tedy případ,
-- kdy plátce uplatnil daň jinak, než stanoví zákon (špatná sazba, chybný výpočet), a ta
-- patří ZPĚTNĚ do období původního plnění, do dodatečného přiznání.
--
-- Proto se eviduje `period_year` / `period_month` původního plnění zvlášť od
-- `delivered_on`: období určuje původní plnění, ale opravu lze provést nejdříve ke dni
-- doručení opravného daňového dokladu (§ 43 odst. 1 a 4).
--
-- ── Sazba a prekluze ────────────────────────────────────────────────────────────────
-- § 43 odst. 2: použije se sazba platná ke dni povinnosti přiznat daň u PŮVODNÍHO plnění,
-- ne dnešní. Proto se ukládá, do které sazbové skupiny oprava patří (ř. 1 vs ř. 2), a ne
-- procento — to už je vlastností původního dokladu.
--
-- § 43 odst. 3: opravu nelze provést po uplynutí lhůty pro stanovení daně (§ 148 DŘ,
-- zpravidla 3 roky). Kontroluje {@see Section43Service}, protože lhůta se počítá od konce
-- období, ne od data dokladu.
--
-- Znaménko je součástí částky: oprava daň na výstupu snižuje i zvyšuje.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS vat_s43_corrections (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id   INT UNSIGNED NOT NULL,
    source_type   ENUM('invoice','purchase_invoice') NOT NULL DEFAULT 'invoice',
    source_id     BIGINT UNSIGNED NOT NULL COMMENT 'doklad, jehož výše daně se opravuje',
    period_year   SMALLINT UNSIGNED NOT NULL COMMENT 'rok PŮVODNÍHO plnění — určuje období opravy',
    period_month  TINYINT UNSIGNED NOT NULL COMMENT 'měsíc původního plnění (u kvartálu libovolný měsíc kvartálu)',
    rate_kind     ENUM('basic','reduced') NOT NULL DEFAULT 'basic'
        COMMENT 'sazbová skupina původního plnění — ř. 1 (21 %) vs ř. 2 (12 %), § 43 odst. 2',
    base_delta    DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'změna základu daně (se znaménkem)',
    vat_delta     DECIMAL(14,2) NOT NULL COMMENT 'změna daně na výstupu (se znaménkem)',
    corrective_doc_number VARCHAR(60) NULL COMMENT 'číslo opravného daňového dokladu (§ 45)',
    delivered_on  DATE NOT NULL COMMENT 'doručení opravného dokladu — dřív opravu provést nelze',
    reason        VARCHAR(255) NOT NULL COMMENT 'čím byla původní výše daně chybná',
    created_by    INT UNSIGNED NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_s43_supplier_period (supplier_id, period_year, period_month),
    KEY ix_s43_source (supplier_id, source_type, source_id),
    CONSTRAINT fk_s43_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
  COMMENT='§ 43 ZDPH — oprava výše daně per doklad, do období původního plnění';
