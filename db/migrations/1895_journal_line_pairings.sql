-- MyÚčto.cz — Párování otevřených položek na libovolném účtu (okruhy)
--
-- Okruh je skupina řádků deníku jednoho účtu (analytiky) jedné firmy, které se
-- navzájem vyrovnávají: převod mezi účty přes 261, záloha a její vyúčtování na 395,
-- půjčka a splátky. Vyrovnaný okruh je uzavřený, nevyrovnaný nechává rozdíl otevřený.
-- Součet otevřených částek se proto vždy rovná zůstatku účtu.
--
-- Párování je METADATA, ne účetní zápis: nemění deník, obraty ani zůstatky, a proto
-- se smí měnit i v uzavřeném nebo zamčeném období. Tabulky nejsou system-versioned.
--
-- Řádek se v položce okruhu drží přes (entry_id, line_no), ne přes id řádku.
-- Přeúčtování dokladu (JournalEntryRepository::replace) řádky zápisu smaže a vloží
-- znovu s novými id, ale se stejným zápisem a pořadím, takže párování přežije.
-- Nesouhlasí-li po přeúčtování na daném pořadí účet, položka z okruhu vypadne
-- a zapíše se to do auditu. Storno zápisu (setReversedBy) řádky originálu z okruhů
-- uvolní stejně. Smazání zápisu je odnese kaskádou.
--
-- Řádek smí být nejvýš v jednom okruhu (PK položky).
--
-- Idempotentní: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS journal_line_pairings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL COMMENT 'Účet (analytika) všech řádků okruhu',
    note VARCHAR(255) NULL,
    origin ENUM('manual','suggestion') NOT NULL DEFAULT 'manual',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_jlp_supplier_id (supplier_id, id),
    KEY idx_jlp_supplier_account (supplier_id, account_id),
    KEY idx_jlp_created_by (created_by),
    CONSTRAINT fk_jlp_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_jlp_account_supplier FOREIGN KEY (supplier_id, account_id)
        REFERENCES chart_of_accounts(supplier_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_jlp_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_line_pairing_items (
    supplier_id INT UNSIGNED NOT NULL,
    entry_id BIGINT UNSIGNED NOT NULL,
    line_no INT UNSIGNED NOT NULL,
    pairing_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL COMMENT 'Účet řádku v okamžiku spárování (kontrola po přeúčtování)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (supplier_id, entry_id, line_no),
    KEY idx_jlpi_pairing (supplier_id, pairing_id),
    CONSTRAINT fk_jlpi_pairing FOREIGN KEY (supplier_id, pairing_id)
        REFERENCES journal_line_pairings(supplier_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_jlpi_entry FOREIGN KEY (supplier_id, entry_id)
        REFERENCES journal_entries(supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
