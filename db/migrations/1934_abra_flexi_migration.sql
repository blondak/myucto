SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS abra_flexi_connections (
    supplier_id INT UNSIGNED NOT NULL,
    credentials_enc LONGTEXT NOT NULL,
    source_fingerprint CHAR(64) NOT NULL,
    connection_version INT UNSIGNED NOT NULL DEFAULT 1,
    discovery JSON NULL,
    selected_years JSON NULL,
    sync_state JSON NULL,
    imported_at TIMESTAMP NULL DEFAULT NULL,
    last_synced_at TIMESTAMP NULL DEFAULT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (supplier_id),
    CONSTRAINT fk_abra_connection_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abra_flexi_imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NULL,
    mode ENUM('dry_run','import') NOT NULL,
    status ENUM('running','completed','completed_with_warnings','failed','cancelled') NOT NULL DEFAULT 'running',
    agenda_year SMALLINT UNSIGNED NULL,
    protocol LONGTEXT NULL CHECK (protocol IS NULL OR JSON_VALID(protocol)),
    automation_snapshot LONGTEXT NULL,
    automation_restored_at TIMESTAMP NULL DEFAULT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY ix_abra_imports_supplier (supplier_id, id),
    CONSTRAINT fk_abra_imports_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abra_flexi_import_map (
    supplier_id INT UNSIGNED NOT NULL,
    kind VARCHAR(32) COLLATE utf8mb4_bin NOT NULL,
    abra_key VARCHAR(190) COLLATE utf8mb4_bin NOT NULL,
    target_type VARCHAR(32) NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    source_hash CHAR(64) NOT NULL,
    source_year SMALLINT UNSIGNED NULL,
    run_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (supplier_id, kind, abra_key),
    KEY ix_abra_map_target (supplier_id, target_type, target_id),
    CONSTRAINT fk_abra_map_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM(
        'idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai', 'monthly_export',
        'document_zip_import', 'document_zip_export', 'document_folder_import',
        'closing_package', 'file_import', 'document_backfill',
        'accounting_setup_analysis', 'accounting_history_reclassification',
        'automation_recommendations', 'scan_attach', 'money_s3_import', 'pohoda_import',
        'premier_import', 'stereo_nx_import', 'money_s3_batch', 'abra_flexi_import'
    ) NOT NULL;
