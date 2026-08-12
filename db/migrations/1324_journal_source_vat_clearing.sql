-- ==========================================================================
-- 1324 — source_type 'vat_clearing' (interní doklad zúčtování DPH)
-- ==========================================================================
-- Doklad, kterým se na konci každého zdaňovacího období převede daň období
-- z analytik 343.100/343.200 na zúčtovací účet 343.900
-- ({@see MyInvoice\Service\Accounting\Vat\VatClearingService}, migrace 1323).
--
-- Vlastní `source_type` je tu kvůli idempotenci: spolu s deterministickým
-- `source_id` (rok/období) tvoří klíč `uq_je_supplier_source`, takže opakovaný
-- běh existující zápis PŘEPÍŠE místo aby založil druhý. Zároveň jde ten typ
-- vyloučit z obratu, ze kterého se doklad počítá — bez toho by si každé
-- přepočítání přičetlo samo sebe.
--
-- Enum se rozšiřuje jen o novou hodnotu; pořadí ani stávající hodnoty se
-- nemění (vzor: migrace 1153 a 1261).

SET NAMES utf8mb4;

-- `journal_entries` je na některých instalacích SYSTEM VERSIONED (auditní historie).
-- ALTER nad takovou tabulkou MariaDB odmítne chybou 4119, dokud se explicitně
-- nepovolí přepsání historických řádků. Stejný přepínač jako v migracích 1153/1261.
SET @@system_versioning_alter_history = 1;

ALTER TABLE journal_entries
  MODIFY source_type ENUM(
    'invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
    'depreciation','asset_disposal','fx_revaluation','stock','provision','income_tax',
    'profit_distribution','offset','small_asset_accrual','prepaid_expense_accrual',
    'settlement','deferred_tax','payroll','vat_clearing'
  ) NOT NULL DEFAULT 'manual';
