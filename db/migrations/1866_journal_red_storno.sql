-- Červené storno v účetním deníku.
--
-- Účetní částka zůstává kladná a stranu stále určuje side. Explicitní příznak
-- red storna mění pouze znaménko účetního účinku; generated sloupce jsou společný
-- SQL zdroj pravdy pro obraty, zůstatky, výkazy a cizoměnové částky.

SET NAMES utf8mb4;

-- journal_entry_lines je system-versioned; ALTER musí zahrnout i historii.
SET @@system_versioning_alter_history = 1;

ALTER TABLE journal_entry_lines
    ADD COLUMN IF NOT EXISTS is_red_storno TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = červené storno: účetní účinek částky je záporný'
        AFTER amount;

ALTER TABLE journal_entry_lines
    ADD COLUMN IF NOT EXISTS signed_amount DECIMAL(15,2)
        AS (IF(is_red_storno = 1, -amount, amount)) PERSISTENT
        COMMENT 'Podepsaná částka pro účetní agregace'
        AFTER is_red_storno;

ALTER TABLE journal_entry_lines
    ADD COLUMN IF NOT EXISTS signed_amount_foreign DECIMAL(15,2)
        AS (CASE WHEN amount_foreign IS NULL THEN NULL
                 WHEN is_red_storno = 1 THEN -amount_foreign
                 ELSE amount_foreign END) PERSISTENT
        COMMENT 'Podepsaná cizoměnová částka pro účetní agregace'
        AFTER amount_foreign;

ALTER TABLE journal_entry_lines
    DROP CONSTRAINT IF EXISTS chk_jel_red_storno_boolean;
ALTER TABLE journal_entry_lines
    ADD CONSTRAINT chk_jel_red_storno_boolean CHECK (is_red_storno IN (0, 1));
