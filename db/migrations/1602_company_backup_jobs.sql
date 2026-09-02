-- Přenositelná záloha firmy: bezpečný lifecycle background jobu.
--
-- Heslo archivu je v DB pouze krátkodobě a kontextově zašifrované aplikačním
-- klíčem. Každý koncový stav je musí ve stejném UPDATE odstranit. Výsledek je
-- naopak úplný a neměnný pouze ve stavu completed; expirace metadata souboru
-- atomicky zahodí. Generovaný UNIQUE klíč nedovolí dvě aktivní zálohy jedné
-- firmy, ale neblokuje souběžné zálohy různých firem.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS company_backup_jobs (
    backup_id            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin
                             NOT NULL PRIMARY KEY,
    supplier_id          INT UNSIGNED NOT NULL,
    status               ENUM(
                             'queued',
                             'checking',
                             'snapshotting',
                             'packaging',
                             'completed',
                             'failed',
                             'cancelled',
                             'expired'
                         ) NOT NULL DEFAULT 'queued',
    registry_fingerprint CHAR(71) CHARACTER SET ascii COLLATE ascii_bin
                             NOT NULL,

    -- AAD zahrnuje firmu, backup_id i registry fingerprint. Plaintext se do DB
    -- ani do logu nikdy nezapisuje.
    password_ciphertext  TEXT NULL,

    total_steps          INT UNSIGNED NULL,
    processed_steps      INT UNSIGNED NOT NULL DEFAULT 0,
    cancel_requested     TINYINT(1) NOT NULL DEFAULT 0,
    last_error_code      VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_error_message   TEXT NULL,

    -- Relativně k RuntimePaths::storage('company-backups'). Cesta je vždy
    -- sup-{supplier_id}/{backup_id}.zip a vzniká pouze přes artifact storage.
    artifact_path        VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    artifact_name        VARCHAR(255) NULL,
    artifact_bytes       BIGINT UNSIGNED NULL,
    artifact_sha256      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    artifact_entry_count INT UNSIGNED NULL,
    expires_at           TIMESTAMP(6) NULL,

    started_at           TIMESTAMP(6) NULL,
    finished_at          TIMESTAMP(6) NULL,
    created_by           BIGINT UNSIGNED NOT NULL,
    created_at           TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at           TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
                             ON UPDATE CURRENT_TIMESTAMP(6),

    active_supplier_id INT UNSIGNED AS (
        IF(
            status IN ('queued', 'checking', 'snapshotting', 'packaging'),
            supplier_id,
            NULL
        )
    ) VIRTUAL,

    KEY idx_company_backup_jobs_supplier (supplier_id, created_at DESC),
    KEY idx_company_backup_jobs_expiry (status, expires_at),
    UNIQUE KEY uq_company_backup_jobs_active (active_supplier_id),

    CONSTRAINT fk_company_backup_jobs_supplier
        FOREIGN KEY (supplier_id) REFERENCES supplier(id),
    CONSTRAINT fk_company_backup_jobs_created_by
        FOREIGN KEY (created_by) REFERENCES users(id),

    CONSTRAINT chk_company_backup_jobs_id CHECK (
        backup_id REGEXP
            '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
    ),
    CONSTRAINT chk_company_backup_jobs_fingerprint CHECK (
        registry_fingerprint REGEXP '^sha256:[0-9a-f]{64}$'
    ),
    CONSTRAINT chk_company_backup_jobs_progress CHECK (
        total_steps IS NULL OR processed_steps <= total_steps
    ),
    CONSTRAINT chk_company_backup_jobs_password CHECK (
        (
            status IN ('queued', 'checking', 'snapshotting', 'packaging')
            AND password_ciphertext IS NOT NULL
            AND LEFT(password_ciphertext, 7) = 'enc:v2:'
        ) OR (
            status IN ('completed', 'failed', 'cancelled', 'expired')
            AND password_ciphertext IS NULL
        )
    ),
    CONSTRAINT chk_company_backup_jobs_artifact CHECK (
        (
            status = 'completed'
            AND artifact_path IS NOT NULL
            AND artifact_name IS NOT NULL
            AND artifact_bytes > 0
            AND artifact_sha256 REGEXP '^[0-9a-f]{64}$'
            AND artifact_entry_count >= 3
            AND expires_at IS NOT NULL
        ) OR (
            status <> 'completed'
            AND artifact_path IS NULL
            AND artifact_name IS NULL
            AND artifact_bytes IS NULL
            AND artifact_sha256 IS NULL
            AND artifact_entry_count IS NULL
            AND expires_at IS NULL
        )
    ),
    CONSTRAINT chk_company_backup_jobs_finished CHECK (
        (
            status IN ('queued', 'checking', 'snapshotting', 'packaging')
            AND finished_at IS NULL
        ) OR (
            status IN ('completed', 'failed', 'cancelled', 'expired')
            AND finished_at IS NOT NULL
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
