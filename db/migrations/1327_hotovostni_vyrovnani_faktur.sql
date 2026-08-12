-- MyÚčto.cz — hotovostní vyrovnání faktury přímo z editoru dokladu.
--
-- PROBLÉM: účetní zvolí na faktuře formu úhrady „Hotově" a tím to skončí. Doklad dál
-- visí jako neuhrazený závazek (321) resp. pohledávka (311), pokladní doklad se musí
-- vyklikat zvlášť v modulu Pokladna a ručně na fakturu navázat. V praxi to nikdo
-- nedělá, takže saldokonto ukazuje otevřené položky, které byly zaplaceny na místě.
--
-- ŘEŠENÍ: k formě úhrady „Hotově" se na dokladu vybere POKLADNA a uložení dokladu
-- z ní vyrobí (a zaúčtuje) pokladní doklad — VPD u přijaté faktury, PPD u vydané.
-- Volba je nepovinná a vratná; službou je CashSettlementService, která veškeré
-- zakládání i rušení pouští přes CashDocumentService (žádná paralelní účtovací cesta).
--
-- 1) `cash_register_id` na obou typech faktur = VOLBA uživatele (co se má stát).
--    NULL = nic se neděje. FK ON DELETE SET NULL: smazaná pokladna volbu zruší,
--    doklad zůstane.
-- 2) `cash_documents.auto_settlement` = OTISK toho, kdo doklad založil. Bez něj by
--    zrušení volby v editoru mazalo i pokladní doklady pořízené ručně v modulu
--    Pokladna — vyrovnání smí rušit jen to, co samo vytvořilo.

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS cash_register_id BIGINT UNSIGNED NULL
      COMMENT 'volba „uhradit hotově z této pokladny" (CashSettlementService); NULL = bez hotovostního vyrovnání'
      AFTER payment_method_source;

ALTER TABLE purchase_invoices
  ADD FOREIGN KEY IF NOT EXISTS fk_pi_cash_register (cash_register_id)
      REFERENCES cash_registers(id) ON DELETE SET NULL;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS cash_register_id BIGINT UNSIGNED NULL
      COMMENT 'volba „inkasovat hotově do této pokladny" (CashSettlementService); NULL = bez hotovostního vyrovnání'
      AFTER payment_method;

ALTER TABLE invoices
  ADD FOREIGN KEY IF NOT EXISTS fk_inv_cash_register (cash_register_id)
      REFERENCES cash_registers(id) ON DELETE SET NULL;

ALTER TABLE cash_documents
  ADD COLUMN IF NOT EXISTS auto_settlement TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = doklad založilo hotovostní vyrovnání z editoru faktury (CashSettlementService); smí ho samo i zrušit'
      AFTER purchase_invoice_id;

ALTER TABLE cash_documents
  ADD INDEX IF NOT EXISTS idx_cashdoc_auto_settlement (supplier_id, auto_settlement, status);
