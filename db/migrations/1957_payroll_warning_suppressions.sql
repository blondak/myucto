-- MyÚčto.cz — Trvale skrytá mzdová varování (Mzdy → Nastavení zaměstnavatele → Skrytá varování)
--
-- Varování kontrol mzdového běhu (např. „Počet osob s nepodepsaným prohlášením
-- poplatníka…") se dosud ukazovala každý měsíc znovu, i když účetní věděla, že je
-- to v pořádku. Skrytí je konfigurace firmy: platí pro všechny další běhy, dokud ho
-- někdo neobnoví.
--
-- ## Skrytí (`payroll_warning_suppressions`)
--
--   • `subject_type = 'supplier'` skryje celý typ varování ve firmě (`subject_id = 0`).
--   • `subject_type = 'employee' | 'employment'` skryje varování jen u konkrétní
--     osoby nebo pracovního vztahu. Který druh subjektu kód nese, určuje katalog
--     v aplikaci (PayrollWarningSuppressionCatalog), jen tam jde skrýt.
--   • Blokující chyby a varování vyžadující výjimku skrýt nejde (hlídá aplikace).
--   • Obnovení řádek smaže; kdo, kdy a proč skryl i obnovil, nese auditní log.
--
-- ## Subjekty souhrnného varování (`payroll_run_validations.subject_ids_json`)
--
-- Souhrnné varování „N osob…" je jeden řádek za běh. Aby šlo skrýt „pro všech
-- N osob", nese seznam id osob, kterých se týká. U ostatních validací je NULL.
--
-- Idempotentní: CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_warning_suppressions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    code VARCHAR(96) NOT NULL COMMENT 'Kód kontroly mzdového běhu (payroll_run_validations.code)',
    subject_type ENUM('supplier','employee','employment') NOT NULL
        COMMENT 'supplier = celý typ ve firmě, jinak konkrétní osoba nebo vztah',
    subject_id BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 u skrytí celého typu',
    reason VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pws_subject (supplier_id, code, subject_type, subject_id),
    CONSTRAINT fk_pws_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_pws_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_pws_subject CHECK (
        (subject_type = 'supplier' AND subject_id = 0)
        OR (subject_type <> 'supplier' AND subject_id > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_run_validations
    ADD COLUMN IF NOT EXISTS subject_ids_json LONGTEXT NULL
        COMMENT 'Id osob souhrnného varování (JSON seznam), jinak NULL';
