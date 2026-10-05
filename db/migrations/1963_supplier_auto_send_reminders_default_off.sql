-- MyÚčto.cz — Automatické upomínky jsou u nově založené firmy vypnuté.
--
-- Zakládání firmy (SetupAction, SupplierCreator) sloupec nevyplňuje a spoléhá
-- na DEFAULT, takže stačí změnit výchozí hodnotu. Existující firmy si své
-- nastavení ponechávají; zapnout upomínky lze v Nastavení firmy.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ALTER COLUMN auto_send_reminders SET DEFAULT 0;
