-- MyÚčto.cz — stvrzenka ke schválenému/zamítnutému výkazu práce.
--
-- PROČ: `approval_token` se při rozhodnutí nuluje, aby odkaz z e-mailu nešel
-- použít podruhé. Důsledek ale je, že schvalovatel, který si tentýž odkaz
-- otevře znovu (běžná věc — vrátí se do e-mailu zkontrolovat, co odklikl),
-- dostane červené „Odkaz není platný". U člověka, kterému všechno vyšlo, to
-- vypadá jako porucha systému.
--
-- Nulování tokenu je správně a zůstává. Místo něj se ukládá jeho SHA-256:
-- z hashe se token zpětně nesestaví, takže rozhodovací endpoint (který hledá
-- podle `approval_token`) zůstává po konzumaci navždy slepý — nedá se jím
-- rozhodnout znovu ani při úniku databáze. Veřejný GET si přes hash dohledá
-- jen READ-ONLY shrnutí: stav, datum a případný důvod zamítnutí, tedy přesně
-- to, co držitel odkazu sám odklikal.
--
-- Sloupec je nullable a bez UNIQUE: starší rozhodnutí hash nemají (token je
-- nenávratně pryč) a jejich odkazy dál skončí na „není platný". Zpětně to
-- dopočítat nejde a je to v pořádku.

SET NAMES utf8mb4;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS approval_receipt_hash CHAR(64) NULL DEFAULT NULL
        COMMENT 'SHA-256 zkonzumovaného approval_token — read-only stvrzenka' AFTER approval_token;

CREATE INDEX IF NOT EXISTS idx_invoices_approval_receipt_hash ON invoices (approval_receipt_hash);
