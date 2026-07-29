-- Uzávěrkový balíček — background job (import_jobs, source='closing_package'), který
-- do jednoho ZIPu sbalí PDF sestavy k uzávěrce účetního období (rozvaha, výsledovka,
-- hlavní kniha, deník, obratová předvaha, kniha DPH, přiznání k dani z příjmů).
-- Rozšiřuje ENUM import_jobs.source o nový typ. MODIFY je idempotentní.

SET NAMES utf8mb4;

ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM(
        'idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai', 'monthly_export',
        'document_zip_import', 'document_zip_export', 'document_folder_import',
        'closing_package'
    ) NOT NULL;
