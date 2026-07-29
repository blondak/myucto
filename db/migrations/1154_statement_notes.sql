-- 1154: Příloha k účetní závěrce — § 18 odst. 1 písm. c) ZoÚ, § 39 / § 39a / § 39b
--       vyhlášky 500/2002 Sb.
--
-- Systém přílohu neměl vůbec: existovala jen rozvaha a výsledovka. Závěrka bez přílohy
-- přitom NENÍ úplná — a `ClosingPackageService` to sám prozrazoval, protože v komentáři
-- u povinného jádra § 18 cituje, ale přílohu mezi částmi nemá.
--
-- POZOR na záměnu pojmů: `$appendix` v `DppoXmlBuilder` je „účetní závěrka jako příloha
-- PŘIZNÁNÍ k DPPO" (věty VetaUA/UB/UD/UZ = rozvaha + výsledovka v celých tisících). To je
-- něco jiného než příloha k závěrce podle § 39 — ta je z převážné části TEXTOVÁ.
--
-- ── Proč volný text, a ne strukturované sloupce ─────────────────────────────────────
-- Většina zveřejňovaných údajů (použité účetní metody, události po rozvahovém dni,
-- informace o odměnách statutárnímu orgánu) jsou souvislé formulace, ne čísla. Vtěsnat je
-- do sloupců by znamenalo předstírat strukturu, kterou vyhláška nemá, a při každém
-- doplnění dalšího § by si vyžádalo migraci. Klíč sekce je proto výčtem v kódu
-- ({@see StatementNotesService}) a tady se drží jen jeho obsah.
--
-- Vazba na rok, ne na období: příloha se sestavuje k závěrce konkrétního účetního období
-- a při jeho znovuotevření musí zůstat (je součástí auditní stopy závěrky).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `statement_notes` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `supplier_id` INT UNSIGNED NOT NULL,
    `fiscal_year` SMALLINT UNSIGNED NOT NULL,

    -- Klíč sekce podle § 39/39a/39b; výčet drží StatementNotesService (SSOT).
    `section_key` VARCHAR(64) NOT NULL,
    `content`     MEDIUMTEXT NULL,

    `updated_by`  INT UNSIGNED NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `uq_sn` (`supplier_id`, `fiscal_year`, `section_key`),
    KEY `idx_sn_year` (`supplier_id`, `fiscal_year`),
    CONSTRAINT `fk_sn_supplier` FOREIGN KEY (`supplier_id`)
        REFERENCES `supplier`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
