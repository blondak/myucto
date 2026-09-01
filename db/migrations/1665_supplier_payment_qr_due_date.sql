-- 1665: samostatné přepínače data splatnosti v platebním QR
--
-- SPAYD pole DT je volitelné. Dosavadní generátor ho přidával vždy: u přijatých
-- faktur se skutečnou splatností, u vystavených omylem s datem generování QR.
-- Výchozí 1 zachovává přítomnost data a vystavené doklady nově použijí skutečné
-- due_date. Vypnutý přepínač pole DT úplně vynechá.

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS invoice_qr_include_due_date TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Zahrnout invoices.due_date do SPAYD QR vystavených dokladů'
        AFTER embed_isdoc;

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS purchase_invoice_qr_include_due_date TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Zahrnout purchase_invoices.due_date do SPAYD QR přijatých dokladů'
        AFTER invoice_qr_include_due_date;
