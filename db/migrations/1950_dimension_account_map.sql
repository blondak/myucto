-- MyÚčto.cz — Účtotvorná dimenze: hodnota dimenze určí analytický účet (Firma → Dimenze)
--
-- Model Money S3/S4: středisko (nebo jiná dimenze) neurčuje jen analytické členění,
-- ale i analytický účet. Náklad 518 se střediskem FVE jde na 518.100, s Kanceláří
-- na 518.200. Zapíná se u typu dimenze (`drives_accounts`) a mapou u hodnoty.
--
-- ## Typ (`dimension_types.drives_accounts`, `drives_accounts_mask`)
--
--   • Účtotvorný je nejvýš jeden typ na firmu — dva typy by o analytice téhož řádku
--     rozhodovaly proti sobě. V rámci jednoho vlastníka (firma, nebo skupina firem) to
--     hlídá unikátní index nad generovaným sloupcem `drives_accounts_owner`; souběh
--     firemního a skupinového typu hlídá aplikace (DimensionAccountMapService).
--   • Maska = výsledkové účty, na které se mapa uplatní (předpony, vyloučení s !),
--     výchozí `5, 6`. Rozvahové účty (saldokonto, banka, DPH) se nemapují nikdy.
--
-- ## Mapa (`dimension_account_map`)
--
-- Hodnota dimenze je u skupiny firem skupinová, účtový rozvrh je ale per firma — mapa
-- proto patří firmě (`supplier_id`): jedna hodnota může mít v každé firmě skupiny jinou
-- analytiku. Řádek = (hodnota, syntetika) → analytika s platností od–do podle data
-- účetního případu. Analytika musí ležet pod toutéž syntetikou a mít stejnou daňovou
-- uznatelnost (kontroluje aplikace při uložení).
--
-- Bez typu s `drives_accounts = 1` a bez řádků mapy se účtuje beze změny.
--
-- Idempotentní: ADD COLUMN / KEY IF NOT EXISTS, CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE dimension_types
    ADD COLUMN IF NOT EXISTS drives_accounts TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Účtotvorná dimenze: hodnota určí analytický účet (nejvýš jeden typ na firmu)',
    ADD COLUMN IF NOT EXISTS drives_accounts_mask VARCHAR(190) NOT NULL DEFAULT '5, 6'
        COMMENT 'Výsledkové účty, na které se mapa hodnot uplatní (předpony, vyloučení s !)',
    ADD COLUMN IF NOT EXISTS drives_accounts_owner VARCHAR(40)
        AS (IF(drives_accounts = 1, CONCAT(IFNULL(supplier_id, ''), ':', IFNULL(supplier_group_id, '')), NULL)) PERSISTENT
        COMMENT 'Vlastník účtotvorného typu — unikátní, ať má firma (skupina) nejvýš jeden';

ALTER TABLE dimension_types
    ADD UNIQUE KEY IF NOT EXISTS uq_dimension_type_drives_accounts (drives_accounts_owner);

CREATE TABLE IF NOT EXISTS dimension_account_map (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    dimension_type_id BIGINT UNSIGNED NOT NULL,
    dimension_value_id BIGINT UNSIGNED NOT NULL,
    synthetic_account_id BIGINT UNSIGNED NOT NULL COMMENT 'Syntetika, na kterou účtuje doklad (518)',
    analytic_account_id BIGINT UNSIGNED NOT NULL COMMENT 'Cílová analytika pod touž syntetikou (518.100)',
    valid_from DATE NULL,
    valid_to DATE NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dam_supplier_synthetic (supplier_id, synthetic_account_id),
    KEY idx_dam_value (supplier_id, dimension_value_id),
    KEY idx_dam_type_value (dimension_type_id, dimension_value_id),
    KEY idx_dam_analytic (analytic_account_id),
    CONSTRAINT fk_dam_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_dam_value FOREIGN KEY (dimension_type_id, dimension_value_id)
        REFERENCES dimension_values(type_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_dam_synthetic FOREIGN KEY (synthetic_account_id) REFERENCES chart_of_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_dam_analytic FOREIGN KEY (analytic_account_id) REFERENCES chart_of_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_dam_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_dam_validity CHECK (valid_from IS NULL OR valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
