-- MyÚčto.cz — H-14: kompletní export dat instance (per firma)
--
-- Hostovaná služba dává zákazníkovi po skončení 60 dnů na stažení dat a retence
-- měsíčních kopií hostingu je jen 3 měsíce. Export tedy NENÍ pohodlí, ale jediná
-- cesta, jak se zákazník dostane ke svému účetnictví po delší době — a jak si ho
-- archivuje na zákonnou dobu (§ 31/32 ZoÚ, § 35 ZDPH).
--
-- Vlastní tabulka (ne `import_jobs`) záměrně:
--   • lifecycle je jiný — hotový archiv EXPIRUJE a maže se (`expires_at`), zatímco
--     import job je jen historie běhu;
--   • archiv nese kontrolní součet celku (`sha256`) i po částech (`manifest`),
--     příznak šifrování a rozsah — do sdílených sloupců import_jobs to nepatří;
--   • `import_jobs.source` je sdílený ENUM, jehož rozšiřování by svázalo tuhle
--     agendu s workerem importů.
--
-- Souběh: `uq_instance_exports_active` je generovaný sloupec s hodnotou
-- supplier_id právě pro queued/running (jinak NULL — a NULL se v UNIQUE indexu
-- neduplikuje). Druhý souběžný export téže firmy tak neodmítá jen aplikační
-- kontrola, ale i databáze. Proti běhu ze dvou procesů (web + CLI) navíc stojí
-- souborový zámek (InstanceExportLock).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS + ADD COLUMN/KEY IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS instance_exports (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Multi-tenant scope. Export je VŽDY per firma — instalace může vést víc firem
    -- (účetní kancelář) a vyexportovat zákazníkovi cizí firmu je únik dat.
    supplier_id      INT UNSIGNED NOT NULL,

    status           ENUM('queued', 'running', 'completed', 'failed', 'cancelled')
                        NOT NULL DEFAULT 'queued',

    -- Zvolené části exportu (list stringů) + volitelné omezení rozsahu dokladů.
    parts            JSON NULL,
    date_from        DATE NULL,
    date_to          DATE NULL,

    -- Progress pro UI polling / CLI výpis.
    total_steps      INT UNSIGNED NULL,
    processed_steps  INT UNSIGNED NOT NULL DEFAULT 0,
    current_step     VARCHAR(160) NULL,
    log_text         MEDIUMTEXT NULL,
    last_error       TEXT NULL,
    cancel_requested TINYINT(1) NOT NULL DEFAULT 0,

    -- Výsledek: relativní cesta v rámci storage/instance-exports (sup-N/soubor.zip).
    result_path      VARCHAR(255) NULL,
    result_name      VARCHAR(255) NULL,
    size_bytes       BIGINT UNSIGNED NULL,

    -- Kontrolní součet celého archivu; po částech je v `manifest`.
    sha256           CHAR(64) NULL,
    encrypted        TINYINT(1) NOT NULL DEFAULT 0,
    manifest         JSON NULL,

    -- Archiv se po expiraci maže (cron-cleanup / --cleanup v CLI).
    expires_at       TIMESTAMP NULL,

    started_at       TIMESTAMP NULL,
    finished_at      TIMESTAMP NULL,
    created_by       BIGINT UNSIGNED NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Jen pro UNIQUE index níž: supplier_id, dokud job běží; jinak NULL.
    active_supplier_id INT UNSIGNED
        AS (IF(status IN ('queued', 'running'), supplier_id, NULL)) VIRTUAL,

    KEY idx_instance_exports_supplier (supplier_id, created_at DESC),
    KEY idx_instance_exports_expiry   (expires_at),
    UNIQUE KEY uq_instance_exports_active (active_supplier_id),

    CONSTRAINT fk_iexp_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id),
    CONSTRAINT fk_iexp_user     FOREIGN KEY (created_by)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Doplnění sloupců pro instalace, kde tabulka vznikla dřívější verzí této migrace.
ALTER TABLE instance_exports ADD COLUMN IF NOT EXISTS manifest   JSON NULL AFTER encrypted;
ALTER TABLE instance_exports ADD COLUMN IF NOT EXISTS expires_at TIMESTAMP NULL AFTER manifest;
