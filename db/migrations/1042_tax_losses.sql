-- MyInvoice.cz — Evidence daňových ztrát (§34 ZDP) + příznak bytové potřeby před 2021
--
-- Fáze E (audit 2026-07): daň z příjmů — dorovnání.
--
--  * tax_losses            = pravomocně stanovené daňové ztráty (rok vzniku, výše),
--                            zdroj = finalizované přiznání se záporným základem (FO i PO).
--  * tax_loss_applications = per-rok uplatnění ztráty (FIFO), aby šlo hlídat 5letou
--                            lhůtu (§34/1) i souhrnný strop bez mutace zdrojové ztráty.
--    Zbývající zůstatek ztráty = amount − Σ applications.amount.
--  * tax_profiles.mortgage_pre_2021 = bytová potřeba obstaraná do 31. 12. 2020 → strop
--    odpočtu úroků 300 000 Kč (§15 odst. 3/4 ZDP), jinak 150 000 Kč (od 2021).
--
-- Idempotence: IF NOT EXISTS. Re-run safe. Tenant izolace přes supplier_id.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tax_losses (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id       INT UNSIGNED NOT NULL,
    taxpayer_type     ENUM('fo','po') NOT NULL,
    origin_year       SMALLINT UNSIGNED NOT NULL COMMENT 'Zdaňovací období vzniku ztráty',
    amount            DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Pravomocně stanovená ztráta (kladná)',
    source_return_id  INT UNSIGNED NULL COMMENT 'income_tax_returns.id finalizovaného přiznání, které ztrátu založilo',
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_tax_loss (supplier_id, taxpayer_type, origin_year),
    KEY idx_tax_loss_supplier (supplier_id, taxpayer_type),
    CONSTRAINT fk_taxloss_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_loss_applications (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id       INT UNSIGNED NOT NULL,
    taxpayer_type     ENUM('fo','po') NOT NULL,
    loss_id           INT UNSIGNED NOT NULL,
    applied_year      SMALLINT UNSIGNED NOT NULL COMMENT 'Zdaňovací období, ve kterém byla ztráta uplatněna',
    applied_return_id INT UNSIGNED NULL COMMENT 'income_tax_returns.id přiznání, které ztrátu uplatnilo',
    amount            DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Uplatněná část ztráty v daném roce',
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_loss_app_applied (supplier_id, taxpayer_type, applied_year),
    KEY idx_loss_app_loss (loss_id),
    CONSTRAINT fk_lossapp_loss FOREIGN KEY (loss_id) REFERENCES tax_losses(id) ON DELETE CASCADE,
    CONSTRAINT fk_lossapp_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tax_profiles
    ADD COLUMN IF NOT EXISTS mortgage_pre_2021 TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Bytová potřeba obstaraná do 31.12.2020 → strop úroků 300k (§15/3-4 ZDP), jinak 150k'
        AFTER mortgage_interest;
