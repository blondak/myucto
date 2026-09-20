-- MyÚčto.cz — vypínač načítání bankovních avíz u IMAP účtu.
--
-- PROČ
--
-- IMAP účet uměl zapnout načítání PDF faktur (`ingest_pdf_invoices`) a PDF výpisů
-- (`ingest_pdf_statements`), ale samotné zpracování avíz z těla e-mailu se vypnout
-- nedalo — jelo vždy, jakmile byl účet povolený. Kdo dostává od banky jen PDF výpisy
-- a avíza nechce (dublují pohyby, které stejně přijdou výpisem), neměl jak je
-- odmítnout jinak než vypnutím celého účtu, čímž by přišel i o ty výpisy.
--
-- CO SE ZAVÁDÍ
--
-- `bank_email_imap_settings.ingest_notices` — samostatný přepínač pro avíza.
-- **DEFAULT 1 = dnešní chování**: existující účty avíza načítají dál a po nasazení
-- se u nich nic nezmění. Je to jediný z trojice přepínačů, který je zapnutý
-- ve výchozím stavu, protože avíza jsou původní a hlavní účel téhle schránky;
-- načítání PDF příloh je naopak opt-in (`DEFAULT 0` v migraci 1854).
--
-- Vypnutím se přeskočí jen rozbor těla zprávy. PDF přílohy se dál zpracují podle
-- svých vlastních přepínačů, takže „jen výpisy, žádná avíza" je platná kombinace.
--
-- Aditivní, idempotentní (ADD COLUMN IF NOT EXISTS — MariaDB 10.6+/11.8 native).

SET NAMES utf8mb4;

ALTER TABLE bank_email_imap_settings
  ADD COLUMN IF NOT EXISTS ingest_notices TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'Načítat bankovní avíza z těla zprávy (1 = chování do migrace 1857)'
    AFTER allow_forwarded;
