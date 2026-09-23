-- Kontrola souběhu se starým účetním systémem (měsíční rekonciliace K1, K5–K13).
--
-- Firma, která ještě účtuje v předchozím programu, porovnává každý měsíc MyÚčto
-- s jeho výstupy (předvaha, saldokonto, DPH a KH, banka, majetek, střediska, výkazy).
-- Jeden řádek = jedna kontrola měsíce: které soubory se nahrály (otisk), výsledek
-- po kritériích, zařazení rozdílů účetní (sedí / už ve zdroji / převod / výklad)
-- a stav cyklu (otevřený, uzavřený). Opakovaná kontrola téhož měsíce je nový řádek,
-- historie cyklů zůstává.
--
-- Idempotence migrace: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS migration_parallel_checks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    period_month CHAR(7) NOT NULL,
    source_system VARCHAR(20) NOT NULL,
    status ENUM('ok','differences','incomplete') NOT NULL,
    cycle_status ENUM('open','closed') NOT NULL DEFAULT 'open',
    inputs LONGTEXT NULL CHECK (inputs IS NULL OR JSON_VALID(inputs)),
    result LONGTEXT NULL CHECK (result IS NULL OR JSON_VALID(result)),
    classifications LONGTEXT NULL CHECK (classifications IS NULL OR JSON_VALID(classifications)),
    note TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_by BIGINT UNSIGNED NULL,
    closed_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY ix_migration_parallel_checks_month (supplier_id, period_month, id),
    CONSTRAINT fk_migration_parallel_checks_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
