-- 1151: § 32 ZoÚ — prodloužení retenční lhůty po dobu daňového řízení („legal hold")
--
-- Doprovod k `RetentionPolicy` (§ 31 ZoÚ, § 35a ZDPH). Lhůty podle § 31 jsou pevné a jdou
-- spočítat z konce účetního období, ale § 32 na ně navazuje: slouží-li záznam jako důkazní
-- prostředek v daňovém řízení, uchovává se po celou dobu, kdy řízení trvá — i po uplynutí
-- lhůty podle § 31.
--
-- Tuhle skutečnost systém z dat nezjistí. Daňová kontrola ani soudní spor se v účetnictví
-- nikde neobjeví, takže bez ručně zadaného záznamu by brána proti smazání pustila ke
-- skartaci přesně ty dokumenty, které správce daně právě prověřuje. Proto se hold ZADÁVÁ
-- a drží se, dokud ho někdo vědomě neuvolní.
--
-- Hold se váže na účetní období (ne na jednotlivý doklad): daňové řízení se vede za
-- zdaňovací období a týká se všech záznamů, které do něj spadají.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `retention_holds` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `supplier_id`   INT UNSIGNED NOT NULL,

    -- Období, jehož záznamy jsou zadržené. NULL = celé účetnictví firmy (např. rozsáhlá
    -- kontrola bez vymezeného období).
    `period_year`   SMALLINT UNSIGNED NULL,

    -- Důvod podle § 32: daňová kontrola, odvolání, soudní spor, jiné řízení.
    `reason`        ENUM('tax_audit','appeal','litigation','other') NOT NULL,
    `description`   VARCHAR(255) NOT NULL COMMENT 'č. j. / spisová značka / popis řízení',

    `placed_on`     DATE NOT NULL,
    `released_on`   DATE NULL COMMENT 'NULL = hold stále trvá',

    `created_by`    INT UNSIGNED NULL,
    `released_by`   INT UNSIGNED NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY `idx_hold_active` (`supplier_id`, `released_on`, `period_year`),
    CONSTRAINT `fk_hold_supplier` FOREIGN KEY (`supplier_id`)
        REFERENCES `supplier`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
