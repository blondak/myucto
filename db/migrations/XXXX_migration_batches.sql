-- Dávkový převod více firem z cizího programu najednou (účetní kancelář, Money S3).
--
-- Dávka běží jako jeden job `money_s3_batch` (import_jobs) se sdíleným průběhem;
-- migration_batch_items drží její položky: jednu nahranou zálohu = jednu firmu,
-- s výsledkem převodu (firma založená nebo existující, rok „od", běh převodu,
-- souhrn kontrol K1–K4, převzatá podaná přiznání, chyba). Souhrnný protokol dávky
-- je výpis položek jobu; podrobný protokol každé firmy zůstává u jejího běhu
-- převodu (money_s3_imports.id = run_id, firma target_supplier_id).
--
-- supplier_id je firma, ze které účetní dávku spustila (vlastník jobu a nahraných
-- záloh); target_supplier_id je převáděná firma.
--
-- Idempotence: MODIFY se všemi členy, CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM(
        'idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai', 'monthly_export',
        'document_zip_import', 'document_zip_export', 'document_folder_import',
        'closing_package', 'file_import', 'document_backfill',
        'accounting_setup_analysis', 'accounting_history_reclassification',
        'automation_recommendations', 'scan_attach', 'money_s3_import', 'pohoda_import',
        'premier_import', 'stereo_nx_import', 'money_s3_batch'
    ) NOT NULL;

CREATE TABLE IF NOT EXISTS migration_batch_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NOT NULL,
    source VARCHAR(32) NOT NULL,
    position INT UNSIGNED NOT NULL,
    token VARCHAR(32) NULL,
    file_name VARCHAR(255) NULL,
    agenda_ico VARCHAR(20) NULL,
    agenda_name VARCHAR(190) NULL,
    target_supplier_id INT UNSIGNED NULL,
    status ENUM('queued','running','completed','completed_with_warnings','failed','skipped','cancelled') NOT NULL DEFAULT 'queued',
    company_action ENUM('created','existing','skipped') NULL,
    from_year SMALLINT UNSIGNED NULL,
    run_id BIGINT UNSIGNED NULL,
    summary LONGTEXT NULL CHECK (summary IS NULL OR JSON_VALID(summary)),
    error VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    finished_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_migration_batch_items_position (job_id, position),
    KEY ix_migration_batch_items_supplier (supplier_id, job_id),
    KEY ix_migration_batch_items_target (target_supplier_id),
    CONSTRAINT fk_migration_batch_items_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_migration_batch_items_job FOREIGN KEY (job_id) REFERENCES import_jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_migration_batch_items_target FOREIGN KEY (target_supplier_id) REFERENCES supplier(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
