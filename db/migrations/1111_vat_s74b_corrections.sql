-- 1111: § 74b ZDPH — korekce odpočtu u neuhrazených závazků (dlužník)
--
-- Audit §2.5 (PODVOJNE-AUDIT.md). Od 1. 1. 2025 musí dlužník-plátce snížit dříve
-- uplatněný odpočet u přijatého zdanitelného plnění, které neuhradil a uplynulo
-- 6 kalendářních měsíců následujících po měsíci splatnosti; po (částečné) úhradě
-- se odpočet ve stejném poměru obnoví.
--
-- Tato tabulka je LEDGER stavu §74b per dotčené plnění + jednotlivé pohyby (snížení /
-- obnovení) v konkrétním daňovém období. "Čistá korekce" plnění = Σ snížení − Σ obnovení;
-- díky tomu netting korektně zvládá částečné úhrady, splátky, zápočty i obnovu.
--
-- Persistence je oddělená od výpočtu: aging výpočet je READ-ONLY (dry-run); teprve
-- vědomé "zaevidování období" zapíše pohyb + auditní stopu. Nic se neúčtuje automaticky.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `vat_s74b_corrections` (
    `id`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `supplier_id`           TINYINT UNSIGNED NOT NULL COMMENT 'tenant (náš plátce-dlužník)',
    `purchase_invoice_id`   BIGINT UNSIGNED NOT NULL COMMENT 'dotčené přijaté plnění',

    -- Daňové období (měsíc), do kterého pohyb korekce spadá.
    `period_year`           SMALLINT UNSIGNED NOT NULL,
    `period_month`          TINYINT UNSIGNED NOT NULL,

    -- Typ pohybu: reduction = snížení dříve uplatněného odpočtu; restoration = obnovení po úhradě.
    `movement`              ENUM('reduction','restoration') NOT NULL,

    -- DPH částka pohybu (kladná absolutní hodnota). reduction snižuje odpočet, restoration obnovuje.
    `vat_amount`            DECIMAL(12,2) NOT NULL,

    -- Kontext výpočtu (pro audit a rekonstrukci).
    `claimed_deduction_vat` DECIMAL(12,2) NOT NULL COMMENT 'původně uplatněný odpočet DPH z dokladu',
    `unpaid_ratio`          DECIMAL(9,6) NOT NULL COMMENT 'podíl neuhrazené části v okamžiku pohybu (0..1)',

    -- Stav dotčeného plnění PO tomto pohybu.
    `state`                 ENUM('identified','corrected','restored') NOT NULL,

    `note`                  VARCHAR(255) NULL,
    `created_by`            INT UNSIGNED NULL COMMENT 'users.id — kdo pohyb zaevidoval',
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY `idx_s74b_supplier_invoice` (`supplier_id`, `purchase_invoice_id`),
    KEY `idx_s74b_period` (`supplier_id`, `period_year`, `period_month`),
    CONSTRAINT `fk_s74b_invoice` FOREIGN KEY (`purchase_invoice_id`)
        REFERENCES `purchase_invoices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
