-- MyÚčto.cz: složené indexy párování plateb (firma + doklad)
--
-- Výrazy „uhrazeno" (PurchaseSettledExpr a obdoba u vydaných faktur) sčítají párování
-- v závislém poddotazu `WHERE pm.supplier_id = d.supplier_id AND pm.purchase_invoice_id = d.id`.
-- Na instalaci s jedinou firmou má `idx_pm_supplier` jedinou hodnotu, a když optimizer
-- dostane zastaralé statistiky (typicky hned po převodu z POHODY), zvolí právě jej: pro
-- každou fakturu pak čte všechna párování firmy. Dashboard tak jel sekundy místo milisekund.
-- Složený index pokryje obě rovnosti a index jen na firmu nahradí i pro cizí klíč.
--
-- Idempotentní (ADD/DROP INDEX IF [NOT] EXISTS, nativní MariaDB).

ALTER TABLE payment_matches
  ADD INDEX IF NOT EXISTS idx_pm_supplier_purchase (supplier_id, purchase_invoice_id),
  ADD INDEX IF NOT EXISTS idx_pm_supplier_invoice (supplier_id, invoice_id);

ALTER TABLE payment_matches
  DROP INDEX IF EXISTS idx_pm_supplier;
