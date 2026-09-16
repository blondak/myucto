-- Převod agendy z programu POHODA (průvodce „Přechod z POHODA").
--
-- pohoda_imports: jeden řádek na běh průvodce (zkouška nanečisto i ostrý převod)
-- s protokolem a stavem automatiky účtování před převodem (stejně jako money_s3_imports
-- po migraci 1811).
--
-- pohoda_import_map: co už z exportu POHODY v MyÚčtu vzniklo. Na tom stojí idempotence:
-- opakovaný převod téhož nebo novějšího exportu založí jen to, co ještě chybí. Klíče dokladů
-- nezávisí na roku agendy (číslo + datum), takže doklad, který POHODA přenese do agendy
-- dalšího roku, se pozná jako už převedený.
--
-- import_jobs.source: převod běží na pozadí jako job `pohoda_import`.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS, MODIFY se všemi členy.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS pohoda_imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NULL,
    mode ENUM('dry_run','import') NOT NULL,
    status ENUM('running','completed','completed_with_warnings','failed','cancelled') NOT NULL DEFAULT 'running',
    agenda_ico VARCHAR(20) NULL,
    agenda_year SMALLINT UNSIGNED NULL,
    pohoda_version VARCHAR(60) NULL,
    export_sha256 CHAR(64) NULL,
    protocol LONGTEXT NULL CHECK (protocol IS NULL OR JSON_VALID(protocol)),
    automation_snapshot LONGTEXT NULL,
    automation_restored_at TIMESTAMP NULL DEFAULT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY ix_pohoda_imports_supplier (supplier_id, id),
    CONSTRAINT fk_pohoda_imports_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE pohoda_imports
    ADD COLUMN IF NOT EXISTS job_id BIGINT UNSIGNED NULL AFTER supplier_id;

CREATE TABLE IF NOT EXISTS pohoda_import_map (
    supplier_id INT UNSIGNED NOT NULL,
    kind VARCHAR(32) COLLATE utf8mb4_bin NOT NULL,
    pohoda_key VARCHAR(190) COLLATE utf8mb4_bin NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (supplier_id, kind, pohoda_key),
    KEY ix_pohoda_import_map_target (supplier_id, kind, target_id),
    CONSTRAINT fk_pohoda_import_map_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM(
        'idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai', 'monthly_export',
        'document_zip_import', 'document_zip_export', 'document_folder_import',
        'closing_package', 'file_import', 'document_backfill',
        'accounting_setup_analysis', 'accounting_history_reclassification',
        'automation_recommendations', 'scan_attach', 'money_s3_import', 'pohoda_import'
    ) NOT NULL;
