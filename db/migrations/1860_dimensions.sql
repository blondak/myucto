-- MyÚčto.cz — Dimenze: analytické členění dokladů a řádků deníku (Firma → Dimenze)
--
-- Dimenze je strom hodnot jednoho typu (Středisko, Projekt, Vozidlo, Lokalita,
-- Obchodní případ nebo vlastní). Hodnota má kód, název, nadřízenou hodnotu a stav
-- (aktivní / uzavřená); volitelně odkaz na vůz z knihy jízd, zakázku nebo středisko.
--
-- ## Úrovně
--
--   • Firemní typ patří jedné firmě (`supplier_id`).
--   • Globální typ patří skupině firem (`supplier_group_id`) a jeho hodnoty sdílí
--     všechny firmy skupiny — projekt vedený přes mateřskou firmu i její SPV se tak
--     dá sečíst napříč firmami. Skupina je nová entita `supplier_groups`, firma do ní
--     patří přes `supplier.supplier_group_id`.
--
-- Hodnota nese vlastníka stejně jako její typ (kopie z typu), aby šla hlídat
-- viditelnost jedním predikátem bez JOINu.
--
-- ## Proč vazební tabulky, ne sloupce
--
-- Počet typů si určuje firma, jeden řádek deníku nese hodnotu KAŽDÉHO typu (nejvýš
-- jednu za typ). Sloupec za typ by znamenal migraci při každém novém typu; vazební
-- tabulka s PK (řádek, typ) unese libovolný počet typů a jednoznačnost hodnoty za typ
-- vynutí sama. Složený FK (typ, hodnota) → dimension_values(type_id, id) hlídá, že
-- hodnota patří k typu, pod kterým je uložená.
--
-- Vazba na řádek deníku je čistě analytická: nemění účet, stranu, částku ani období.
-- Proto tabulka NENÍ system-versioned a smí se přerazítkovat i u uzavřeného období
-- (stejný důvod jako `journal_entry_lines.project_id`, issue #29). V deníku leží jen
-- id hodnoty — nikdy jméno odpovědné osoby, to je atribut hodnoty.
--
-- `document_dimensions` drží dimenze dokladů (hlavička = item_no 0, položka = pořadí
-- položky od 1, řádek šablony = jeho line_no). Doklady mají různé tabulky a položky
-- se při uložení mažou a zakládají znovu, proto odkaz na pořadí, ne na id položky.
--
-- `journal_entry_lines.cost_center` (text) zůstává beze změny — píší do něj mzdy
-- i ruční zápisy. Hodnota typu Středisko se na číselník středisek váže přes
-- `cost_center_id`, sestavy po středisku proto počítají i řádky, které nesou jen
-- textový kód střediska.
--
-- Idempotentní: CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS, FK přes
-- DROP IF EXISTS + ADD (vzor migrace 1507).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_groups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS supplier_group_id INT UNSIGNED NULL
        COMMENT 'Skupina firem (globální dimenze sdílené firmami skupiny)',
    ADD COLUMN IF NOT EXISTS dimensions_enabled TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Firma → Dimenze zapnuté (opt-in)';

ALTER TABLE supplier
    ADD KEY IF NOT EXISTS idx_supplier_group (supplier_group_id);

ALTER TABLE supplier
    DROP FOREIGN KEY IF EXISTS fk_supplier_group;
ALTER TABLE supplier
    ADD CONSTRAINT fk_supplier_group
        FOREIGN KEY (supplier_group_id) REFERENCES supplier_groups(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS dimension_types (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NULL,
    supplier_group_id INT UNSIGNED NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(100) NOT NULL,
    kind ENUM('cost_center','project','vehicle','location','deal','custom') NOT NULL DEFAULT 'custom',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    show_on_documents TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dimension_type_supplier_code (supplier_id, code),
    UNIQUE KEY uq_dimension_type_group_code (supplier_group_id, code),
    CONSTRAINT fk_dimension_type_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_dimension_type_group FOREIGN KEY (supplier_group_id) REFERENCES supplier_groups(id) ON DELETE CASCADE,
    CONSTRAINT chk_dimension_type_owner CHECK ((supplier_id IS NULL) <> (supplier_group_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dimension_values (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type_id BIGINT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NULL,
    supplier_group_id INT UNSIGNED NULL,
    parent_id BIGINT UNSIGNED NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(190) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    responsible_user_id BIGINT UNSIGNED NULL COMMENT 'Odpovědná osoba — jen atribut hodnoty, do deníku se nekopíruje',
    responsible_note VARCHAR(190) NULL,
    car_id INT UNSIGNED NULL,
    project_id BIGINT UNSIGNED NULL,
    cost_center_id BIGINT UNSIGNED NULL,
    note VARCHAR(500) NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dimension_value_type_code (type_id, code),
    UNIQUE KEY uq_dimension_value_type_id (type_id, id),
    KEY idx_dimension_value_supplier (supplier_id, type_id),
    KEY idx_dimension_value_group (supplier_group_id, type_id),
    KEY idx_dimension_value_parent (parent_id),
    KEY idx_dimension_value_car (car_id),
    KEY idx_dimension_value_project (project_id),
    KEY idx_dimension_value_cost_center (cost_center_id),
    KEY idx_dimension_value_responsible (responsible_user_id),
    CONSTRAINT fk_dimension_value_type FOREIGN KEY (type_id) REFERENCES dimension_types(id) ON DELETE CASCADE,
    CONSTRAINT fk_dimension_value_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_dimension_value_group FOREIGN KEY (supplier_group_id) REFERENCES supplier_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_dimension_value_parent FOREIGN KEY (parent_id) REFERENCES dimension_values(id) ON DELETE RESTRICT,
    CONSTRAINT fk_dimension_value_responsible FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_dimension_value_car FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE SET NULL,
    CONSTRAINT fk_dimension_value_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_dimension_value_cost_center FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id) ON DELETE SET NULL,
    CONSTRAINT chk_dimension_value_owner CHECK ((supplier_id IS NULL) <> (supplier_group_id IS NULL)),
    CONSTRAINT chk_dimension_value_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_entry_line_dimensions (
    line_id BIGINT UNSIGNED NOT NULL,
    dimension_type_id BIGINT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    dimension_value_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (line_id, dimension_type_id),
    KEY idx_jeld_supplier_value (supplier_id, dimension_value_id),
    KEY idx_jeld_type_value (dimension_type_id, dimension_value_id),
    CONSTRAINT fk_jeld_line FOREIGN KEY (line_id) REFERENCES journal_entry_lines(id) ON DELETE CASCADE,
    CONSTRAINT fk_jeld_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_jeld_value FOREIGN KEY (dimension_type_id, dimension_value_id)
        REFERENCES dimension_values(type_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_dimensions (
    supplier_id INT UNSIGNED NOT NULL,
    doc_type ENUM('purchase_invoice','invoice','cash_document','bank_transaction','journal_template') NOT NULL,
    doc_id BIGINT UNSIGNED NOT NULL,
    item_no SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = hlavička, jinak pořadí položky od 1 (u šablony line_no)',
    dimension_type_id BIGINT UNSIGNED NOT NULL,
    dimension_value_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (supplier_id, doc_type, doc_id, item_no, dimension_type_id),
    KEY idx_docdim_value (supplier_id, dimension_value_id),
    KEY idx_docdim_type_value (dimension_type_id, dimension_value_id),
    CONSTRAINT fk_docdim_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_docdim_value FOREIGN KEY (dimension_type_id, dimension_value_id)
        REFERENCES dimension_values(type_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
