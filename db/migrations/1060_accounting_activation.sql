-- MyÚčto.cz — E2 Aktivace podvojného účetnictví.
-- Řízený backfill, otevírací rozvaha a stav aktivačního průvodce.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS accounting_backfill_jobs (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id      INT UNSIGNED NOT NULL,
    kind             ENUM('dry_run','execute') NOT NULL,
    status           ENUM('queued','running','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
    phase            ENUM('opening','documents','cash','bank') NULL,
    params           JSON NULL,
    total_items      INT UNSIGNED NULL,
    processed        INT UNSIGNED NOT NULL DEFAULT 0,
    report_json      JSON NULL,
    log_text         MEDIUMTEXT NULL,
    last_error       TEXT NULL,
    cancel_requested TINYINT(1) NOT NULL DEFAULT 0,
    created_by       INT UNSIGNED NOT NULL,
    started_at       DATETIME NULL,
    finished_at      DATETIME NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    active_flag      TINYINT AS (IF(status IN ('queued','running'), 1, NULL)) STORED,
    UNIQUE KEY uq_abj_active (supplier_id, active_flag),
    KEY idx_abj_supplier (supplier_id, status, id),
    CONSTRAINT fk_abj_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_opening_balances (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id  INT UNSIGNED NOT NULL,
    account_code VARCHAR(10) NOT NULL,
    side         ENUM('debit','credit') NOT NULL,
    amount       DECIMAL(14,2) NOT NULL,
    note         VARCHAR(255) NULL,
    source       ENUM('manual','transition_report') NOT NULL DEFAULT 'manual',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aob (supplier_id, account_code, side),
    CONSTRAINT fk_aob_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS accounting_starts_on DATE NULL
        COMMENT 'Datum zahájení PÚ; starší doklady se backfillu neúčastní'
        AFTER accounting_mode,
    ADD COLUMN IF NOT EXISTS accounting_activation_status
        ENUM('none','draft','running','completed','failed') NOT NULL DEFAULT 'none'
        COMMENT 'Stav průvodce aktivací podvojného účetnictví'
        AFTER accounting_starts_on;
