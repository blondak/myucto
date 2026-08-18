-- MyÚčto.cz — přehled čerpání ročních košů osvobození za firmu.
--
-- Migrace 1480 zavedla koš podle § 6 odst. 9 ZDP, ale čte se z něj vždy jen za
-- JEDNU osobu (`annualBasketTotal`), takže stávající index
-- `idx_payroll_benefit_year (supplier_id, employee_id, component_id, tax_year,
-- status)` stačil — vede se `employee_id`.
--
-- Přehled za firmu se ptá opačně: všechny osoby jednoho roku. Nejlevější
-- prefix by byl jen `supplier_id`, takže by se skenovaly i akumulátory všech
-- ostatních let. Proto vlastní index vedený rokem.
--
-- Žádný nový sloupec ani data se nemění — přehled výhradně ČTE zmrazený rozpad.

SET NAMES utf8mb4;

ALTER TABLE payroll_benefit_accumulators
  ADD INDEX IF NOT EXISTS idx_payroll_benefit_year_scan
    (supplier_id, tax_year, status, employee_id);
