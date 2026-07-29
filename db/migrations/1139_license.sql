-- MyÚčto.cz — E4 Licencování a aktivace.
-- Single-row tabulka `license` drží stav licence instance: trial, instance UUID,
-- fingerprint, licenční klíč, poslední podepsaný token (a jeho dekódovaný payload
-- v cache), counter pro detekci klonů a metadata poslední kontroly u serveru.
--
-- Idempotentní: CREATE TABLE IF NOT EXISTS + INSERT ... WHERE NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS license (
    id               TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
    instance_id      CHAR(36) NOT NULL,
    fingerprint      VARCHAR(64) NULL,
    trial_started_at DATETIME NOT NULL,
    license_key      VARCHAR(64) NULL,
    token            TEXT NULL,
    token_payload    JSON NULL,
    counter          BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_nonce       VARCHAR(64) NULL,
    last_check_at    DATETIME NULL,
    last_check_ok    TINYINT(1) NOT NULL DEFAULT 1,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_license_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed jediného řádku (instance UUID + start trialu). Fingerprint se dopočítá
-- lazily při prvním requestu (závisí na hostname + DB + URL běhu).
INSERT INTO license (id, instance_id, fingerprint, trial_started_at)
SELECT 1, UUID(), NULL, NOW()
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM license);
