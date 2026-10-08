-- MyÚčto.cz — předmět e-mailu a název přiloženého PDF podle klienta (myinvoice#277).
--
-- Firemní odběratelé často zpracovávají přijaté faktury automaticky a předepisují,
-- jak se má jmenovat předmět e-mailu a přiložený soubor. Obojí je volitelná šablona
-- se zástupnými znaky ({VS}, {MM}, {DUZP_MM}, {KLIENT}…), kterou vykresluje
-- ClientEmailFormat. NULL = výchozí předmět podle e-mailové šablony a výchozí
-- název souboru (Faktura-{VS}.pdf).
SET NAMES utf8mb4;

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS email_subject_format VARCHAR(200) NULL
    COMMENT 'Šablona předmětu e-mailu s fakturou; NULL = výchozí',
  ADD COLUMN IF NOT EXISTS email_attachment_name_format VARCHAR(120) NULL
    COMMENT 'Šablona názvu přiloženého PDF bez přípony; NULL = výchozí';
