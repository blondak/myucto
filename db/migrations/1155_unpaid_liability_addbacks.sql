-- 1155: § 23 odst. 3 písm. a) bod 12 ZDP — dopočet neuhrazených dluhů po 30 měsících
--
-- Systém data MÁ (splatnost přijatých faktur i stav úhrady), dopočet ale nedělal a ani
-- neupozornil → základ daně vycházel podhodnocený. Audit to vedl mezi vysokými riziky
-- právě kvůli té kombinaci: mlčící systém nad daty, ze kterých se odpověď dá spočítat.
--
-- ── Proč ledger, a ne jen návrh ─────────────────────────────────────────────────────
-- Zvýšení základu není jednorázová událost. Podle § 23 odst. 3 písm. c) bodu 6 se při
-- pozdější úhradě dluhu základ o tutéž částku zase SNÍŽÍ. Bez evidence toho, co už bylo
-- připočteno, by se protistrana nedala spočítat — a poplatník by zaplatil daň dvakrát:
-- jednou z připočtení, podruhé tím, že by se snížení nikdy neuplatnilo.
--
-- Model je shodný s § 74b (migrace 1111) a § 46 (1150): čistý stav = Σ increase − Σ decrease,
-- pohyb období = cílový stav minus dosud evidovaný. Částečné úhrady a splátky tím vyjdou
-- samy. Je to potřetí, co se tenhle tvar v repu opakuje — proto stejné názvy sloupců.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `tax_unpaid_liability_addbacks` (
    `id`                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `supplier_id`         INT UNSIGNED NOT NULL,
    `purchase_invoice_id` BIGINT UNSIGNED NOT NULL,

    -- Zdaňovací období, do kterého pohyb spadá.
    `fiscal_year`         SMALLINT UNSIGNED NOT NULL,

    -- increase = připočtení k základu (§ 23/3/a/12)
    -- decrease = snížení po úhradě dluhu (§ 23/3/c/6)
    `movement`            ENUM('increase','decrease') NOT NULL,
    `amount`              DECIMAL(14,2) NOT NULL COMMENT 'kladná absolutní hodnota pohybu',

    -- Kontext výpočtu pro audit a rekonstrukci.
    `liability_total`     DECIMAL(14,2) NOT NULL COMMENT 'celková výše dluhu z dokladu',
    `unpaid_ratio`        DECIMAL(9,6) NOT NULL COMMENT 'neuhrazený podíl v okamžiku pohybu (0..1)',

    `note`                VARCHAR(255) NULL,
    `created_by`          INT UNSIGNED NULL,
    `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY `idx_ula_supplier_invoice` (`supplier_id`, `purchase_invoice_id`),
    KEY `idx_ula_year` (`supplier_id`, `fiscal_year`),
    CONSTRAINT `fk_ula_invoice` FOREIGN KEY (`purchase_invoice_id`)
        REFERENCES `purchase_invoices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
