-- MyÚčto.cz — baseline historie plátcovství DPH pro firmy založené mimo SettingsAction.
--
-- PROČ: supplier_vat_status_history (migrace 1120) seedovala baseline jen existujícím
-- firmám v okamžiku migrace; firmy založené později přes Setup wizard (SetupAction),
-- bin/setup.php nebo bin/ci-seed.php řádek historie nedostaly a čtení "plátce k datu"
-- u nich jede přes fallback na živý supplier.is_vat_payer (= dnešní stav pro celou
-- minulost). Kód seedů je opraven souběžně (VatStatusService::seedInitialStatus);
-- tahle migrace dorovnává už existující firmy.
--
-- Baseline se doplňuje POUZE firmám bez jakéhokoli řádku historie: firma s existujícími
-- řádky (změna přes Nastavení) může mít před svým nejstarším řádkem záměrně jiný stav
-- a dopočítávat ho zpětně z dnešní hodnoty by bylo horší než ponechat fallback.
--
-- Idempotence: INSERT IGNORE + NOT EXISTS (vzor 1120, 1179).

SET NAMES utf8mb4;

INSERT IGNORE INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer)
SELECT s.id, '1900-01-01', s.is_vat_payer
  FROM supplier s
 WHERE NOT EXISTS (
     SELECT 1 FROM supplier_vat_status_history h WHERE h.supplier_id = s.id
 );
