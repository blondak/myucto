-- MyÚčto.cz — Pravidla dimenzí podle účtu a rozpad řádku mezi více hodnot (Firma → Dimenze)
--
-- ## Pravidla (`dimension_account_rules`)
--
-- Pravidlo říká: řádek zápisu na účtu, který odpovídá masce, musí (nebo by měl)
-- nést hodnotu daného typu dimenze. Maska je seznam předpon účtu oddělených čárkou,
-- vyloučení začíná vykřičníkem: `5, 6, !59, !69` = třídy 5 a 6 kromě daně z příjmů.
--
--   • `enforcement` = `error` → doklad ani ruční zápis bez hodnoty nejde zaúčtovat
--     (chyba jmenuje účet a chybějící dimenzi); `warning` → zaúčtuje se a volající
--     dostane varování; `none` → pravidlo jen doplňuje výchozí hodnotu.
--   • `default_value_id` = výchozí hodnota firmy pro tento rozsah účtů (např. SPV
--     = její projekt). Doplní se jen tam, kde řádek hodnotu typu nemá ani z dokladu,
--     zakázky, klienta, ani ručně.
--   • `default_from_card` = u typu Vozidlo doplnit vozidlo podle platební karty
--     (koncovka karty z bankovního pohybu nebo přijatého dokladu → držitel → jeho vůz).
--   • `valid_from` / `valid_to` = platnost podle data účetního případu; pravidlo
--     zavedené dnes tak nezablokuje přeúčtování starších dokladů.
--
-- Pravidla platí jen u firmy se zapnutými dimenzemi. Bez pravidla se účtuje beze změny.
--
-- ## Rozpad (`journal_entry_line_dimension_splits`, `document_dimension_splits`)
--
-- `journal_entry_line_dimensions` má PK (řádek, typ), takže řádek nese za typ jedinou
-- hodnotu. Rozpad řádku mezi víc hodnot téhož typu (náklad 60 % středisko A, 40 % B)
-- je proto samostatná tabulka s podílem. Řádek deníku se kvůli rozpadu NEDĚLÍ:
-- `journal_entry_lines` je system-versioned a rozdělení by měnilo částky zápisu.
-- Pro jeden (řádek, typ) platí buď jediná hodnota v `journal_entry_line_dimensions`,
-- nebo rozpad tady — nikdy obojí (hlídá DimensionAssignmentRepository).
--
-- `share` je podíl 0–1 s deseti desetinnými místy: rozpad zadaný částkou se tak
-- promítne na haléř přesně (procenta i částky se ukládají jako podíl). Sestavy
-- rozdělují částku řádku podle podílů po haléřích se zbytkem na největší podíl
-- (DimensionStamper::distributeCents), takže součet sedí přesně.
--
-- `document_dimension_splits` je totéž pro doklady (hlavička = item_no 0, položka =
-- pořadí od 1, stejně jako `document_dimensions`); zaúčtování ho přenese na řádky.
--
-- Idempotentní: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS dimension_account_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    dimension_type_id BIGINT UNSIGNED NOT NULL,
    account_mask VARCHAR(190) NOT NULL COMMENT 'Předpony účtů oddělené čárkou, vyloučení s !',
    enforcement ENUM('error','warning','none') NOT NULL DEFAULT 'error',
    default_value_id BIGINT UNSIGNED NULL COMMENT 'Výchozí hodnota, když řádek typ nemá',
    default_from_card TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Vozidlo podle platební karty',
    valid_from DATE NULL,
    valid_to DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dar_supplier (supplier_id, is_active),
    KEY idx_dar_type_value (dimension_type_id, default_value_id),
    CONSTRAINT fk_dar_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_dar_type FOREIGN KEY (dimension_type_id) REFERENCES dimension_types(id) ON DELETE CASCADE,
    CONSTRAINT fk_dar_value FOREIGN KEY (dimension_type_id, default_value_id)
        REFERENCES dimension_values(type_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_dar_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_dar_validity CHECK (valid_from IS NULL OR valid_to IS NULL OR valid_to >= valid_from),
    CONSTRAINT chk_dar_flags CHECK (is_active IN (0, 1) AND default_from_card IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_entry_line_dimension_splits (
    line_id BIGINT UNSIGNED NOT NULL,
    dimension_type_id BIGINT UNSIGNED NOT NULL,
    dimension_value_id BIGINT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    share DECIMAL(12,10) NOT NULL COMMENT 'Podíl řádku 0–1; součet za (řádek, typ) = 1',
    PRIMARY KEY (line_id, dimension_type_id, dimension_value_id),
    KEY idx_jelds_supplier_value (supplier_id, dimension_value_id),
    KEY idx_jelds_type_value (dimension_type_id, dimension_value_id),
    CONSTRAINT fk_jelds_line FOREIGN KEY (line_id) REFERENCES journal_entry_lines(id) ON DELETE CASCADE,
    CONSTRAINT fk_jelds_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_jelds_value FOREIGN KEY (dimension_type_id, dimension_value_id)
        REFERENCES dimension_values(type_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_jelds_share CHECK (share > 0 AND share <= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_dimension_splits (
    supplier_id INT UNSIGNED NOT NULL,
    doc_type ENUM('purchase_invoice','invoice','cash_document','bank_transaction','journal_template') NOT NULL,
    doc_id BIGINT UNSIGNED NOT NULL,
    item_no SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = hlavička, jinak pořadí položky od 1',
    dimension_type_id BIGINT UNSIGNED NOT NULL,
    dimension_value_id BIGINT UNSIGNED NOT NULL,
    share DECIMAL(12,10) NOT NULL,
    PRIMARY KEY (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id),
    KEY idx_docdims_value (supplier_id, dimension_value_id),
    KEY idx_docdims_type_value (dimension_type_id, dimension_value_id),
    CONSTRAINT fk_docdims_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_docdims_value FOREIGN KEY (dimension_type_id, dimension_value_id)
        REFERENCES dimension_values(type_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_docdims_share CHECK (share > 0 AND share <= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
