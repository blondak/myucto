-- Identita objektů importovaných ze Stereo NX. UID v NX1 není identita řádku;
-- zdrojové klíče jsou složené z řady a čísla, v rámci IČO a vybrané firmy zálohy.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stereo_nx_import_map (
    supplier_id INT UNSIGNED NOT NULL,
    source_ico CHAR(8) COLLATE utf8mb4_bin NOT NULL,
    source_company_index INT UNSIGNED NOT NULL,
    kind VARCHAR(32) COLLATE utf8mb4_bin NOT NULL,
    source_key VARCHAR(190) COLLATE utf8mb4_bin NOT NULL,
    source_hash CHAR(64) COLLATE utf8mb4_bin NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (supplier_id, source_ico, source_company_index, kind, source_key),
    KEY ix_stereo_nx_import_map_target (supplier_id, kind, target_id),
    CONSTRAINT fk_stereo_nx_import_map_supplier FOREIGN KEY (supplier_id)
        REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- V integrační větvi tato tabulka krátce vznikla jako 1863; nová 1863 z masteru
-- patří indexům párování. Schéma je stejné a starý název už není mezi soubory.
DELETE FROM migrations WHERE filename = '1863_stereo_nx_import.sql';
