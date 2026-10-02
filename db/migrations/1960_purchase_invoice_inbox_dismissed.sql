-- MyÚčto.cz — Smazané koncepty z inbox adresáře se znovu neimportují (issue #118)
--
-- Inbox scanner soubory po importu nepřesouvá a za zpracovaný považuje soubor jen
-- tehdy, když jeho SHA-256 nese některá přijatá faktura (`pdf_hash` / `source_hash`).
-- Smazáním konceptu ta vazba zmizela a další běh cronu založil ze stejného souboru
-- nový koncept. Hashe smazaných dokladů si proto pamatujeme zvlášť a scanner je
-- přeskakuje. Ruční nahrání téhož souboru tabulku nečte.
--
-- Idempotentní: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_invoice_inbox_dismissed (
    supplier_id INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL COMMENT 'SHA-256 souboru (PDF nebo strojový originál)',
    purchase_invoice_id BIGINT UNSIGNED NULL COMMENT 'ID smazaného dokladu, jen pro dohledání v logu',
    vendor_invoice_number VARCHAR(64) NULL,
    dismissed_by BIGINT UNSIGNED NULL,
    dismissed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (supplier_id, sha256),
    CONSTRAINT fk_piid_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_piid_user FOREIGN KEY (dismissed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
