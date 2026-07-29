-- EP-8/M2: deduplikace jednotlivých bankovních transakcí napříč překrývajícími se výpisy.
-- NULL zachovává historické řádky; nové importy zapisují stabilní SHA-256 fingerprint.

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

ALTER TABLE bank_transactions
    ADD COLUMN IF NOT EXISTS import_fingerprint CHAR(64) NULL
        COMMENT 'Otisk pohybu pro deduplikaci napříč výpisy' AFTER bank_ref;

ALTER TABLE bank_transactions
    ADD UNIQUE KEY IF NOT EXISTS uq_bt_import_fingerprint (import_fingerprint);
