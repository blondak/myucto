-- 1150: § 46 až § 46g ZDPH — oprava základu daně u nedobytné pohledávky (VĚŘITEL)
--
-- Protějšek ledgeru § 74b (migrace 1111, dlužnická strana). Systém dosud věřitelskou
-- opravu neuměl vůbec: existoval jen ručně nastavitelný příznak `kh_bad_debt='P'`, který
-- se promítl do atributu `zdph_44` v KH A.4 — takže šlo podat kontrolní hlášení s příznakem
-- opravy, aniž by v přiznání vznikla jakákoli částka, a nic na ten rozpor neupozornilo
-- (nález N-021).
--
-- ── Proč se NEODVOZUJE automaticky ──────────────────────────────────────────────────
-- Na rozdíl od § 74b, kde je spouštěčem samotné plynutí času (6 měsíců po splatnosti)
-- a korekce je POVINNÁ, je věřitelská oprava PRÁVEM („věřitel je oprávněn provést opravu")
-- a váže se na právní skutečnost, kterou účetní systém nevidí: insolvenci, exekuci trvající
-- aspoň dva roky, smrt dlužníka, likvidaci. Navíc se provede až vystavením a DORUČENÍM
-- opravného daňového dokladu (§ 46a–46e). Proto se oprava ZADÁVÁ, ne odvozuje — systém
-- kontroluje to, co z dat ověřit lze (neuhrazenost, výše, lhůty, souběh s dřívějšími
-- opravami), a zbytek nechává na doložení uživatelem.
--
-- Období opravy určuje datum doručení opravného dokladu dlužníkovi (§ 46f), ne splatnost.
--
-- ── Netting ─────────────────────────────────────────────────────────────────────────
-- Stejný model jako § 74b: čistá oprava = Σ correction − Σ restoration. Dojde-li později
-- k úhradě, vzniká povinnost daň ve stejném poměru zvýšit zpět (§ 46e) — to už systém
-- z úhrad odvodit UMÍ a dělá to automatickým nettingem proti evidovanému stavu.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `vat_s46_corrections` (
    `id`                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `supplier_id`        TINYINT UNSIGNED NOT NULL COMMENT 'tenant (náš plátce-věřitel)',
    `invoice_id`         BIGINT UNSIGNED NOT NULL COMMENT 'dotčené vydané plnění',

    -- Zdaňovací období, do kterého pohyb spadá. U opravy = období doručení opravného
    -- daňového dokladu (§ 46f); u obnovy = období úhrady.
    `period_year`        SMALLINT UNSIGNED NOT NULL,
    `period_month`       TINYINT UNSIGNED NOT NULL,

    -- correction  = snížení daně na výstupu u nedobytné pohledávky (§ 46)
    -- restoration = zvýšení zpět po (částečné) úhradě (§ 46e)
    `movement`           ENUM('correction','restoration') NOT NULL,

    -- DPH částka pohybu (kladná absolutní hodnota).
    `vat_amount`         DECIMAL(12,2) NOT NULL,

    -- Kontext výpočtu (audit a rekonstrukce).
    `output_vat`         DECIMAL(12,2) NOT NULL COMMENT 'daň na výstupu z původního dokladu',
    `unpaid_ratio`       DECIMAL(9,6) NOT NULL COMMENT 'podíl neuhrazené části v okamžiku pohybu (0..1)',

    -- Právní důvod podle § 46 odst. 1. U obnovy se přebírá z opravy.
    `legal_ground`       ENUM('insolvency','execution','death','liquidation','small_receivable') NOT NULL,

    -- Opravný daňový doklad (§ 46a–46e) a datum jeho doručení dlužníkovi (§ 46f).
    `corrective_doc_number` VARCHAR(60) NULL,
    `delivered_on`          DATE NULL,

    `note`               VARCHAR(255) NULL,
    `created_by`         INT UNSIGNED NULL COMMENT 'users.id — kdo pohyb zaevidoval',
    `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY `idx_s46_supplier_invoice` (`supplier_id`, `invoice_id`),
    KEY `idx_s46_period` (`supplier_id`, `period_year`, `period_month`),
    CONSTRAINT `fk_s46_invoice` FOREIGN KEY (`invoice_id`)
        REFERENCES `invoices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
