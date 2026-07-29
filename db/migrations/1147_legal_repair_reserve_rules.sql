-- MyÚčto.cz — kontace pro zákonnou rezervu na opravy hmotného majetku (ZoR § 7).
--
-- PROČ: účty 451 (rezervy podle zvláštních právních předpisů) a 552 (tvorba a zúčtování
-- zákonných rezerv) byly v účtové osnově od začátku, ale NEEXISTOVALA k nim kontace.
-- Jediné seedované rezervní kontace byly `reserve.other.*` (554/459), tedy ÚČETNÍ rezerva,
-- která je podle § 25 ZDP daňově NEUZNATELNÁ (554 je v NON_DEDUCTIBLE_SYNTHETICS).
-- Daňovou rezervu na opravy tak v systému nešlo uplatnit vůbec — musela se zaúčtovat
-- ručním zápisem mimo uzávěrkový průvodce.
--
-- Rozdíl proti `reserve.other` je věcný, ne kosmetický:
--   552/451 = ZÁKONNÁ rezerva (ZoR § 7), daňově UZNATELNÝ náklad
--   554/459 = ostatní (účetní) rezerva, daňově NEUZNATELNÝ náklad (§ 25 ZDP)
-- Proto samostatná dvojice kontací, ne přepínač nad jednou.
--
-- Idempotence: WHERE NOT EXISTS na (supplier_id IS NULL, rule_key, priority) — shodně
-- s migrací 1006. UNIQUE (supplier_id, rule_key, priority) proti duplicitám nechrání,
-- protože NULL != NULL.

SET NAMES utf8mb4;

INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, s.rule_key, s.description, s.debit_account_code, s.credit_account_code, 0, 1
FROM (
              SELECT 'reserve.repairs.create'  AS rule_key, 'Tvorba zákonné rezervy na opravy HM (ZoR §7)'            AS description, '552' AS debit_account_code, '451' AS credit_account_code
    UNION ALL SELECT 'reserve.repairs.release',             'Čerpání / zrušení zákonné rezervy na opravy HM (ZoR §7)', '451', '552'
) AS s
WHERE NOT EXISTS (
  SELECT 1 FROM posting_rules pr
  WHERE pr.supplier_id IS NULL AND pr.rule_key = s.rule_key AND pr.priority = 0
);
