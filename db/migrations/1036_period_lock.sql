-- MyÚčto.cz — B8 (audit 2026-07, core-posting): měkký zámek účtování k datu (soft-close).
--
-- Jemnější granularita nezměnitelnosti než celý fiskální rok (accounting_periods.status):
-- doklady s entry_date <= locked_until backend odmítne zaúčtovat/re-postnout
-- (PostingService, kód chyby 'date_locked'); storno zamčeného zápisu je možné jen s
-- protizápisem do otevřeného (nezamčeného) data. Zámek se automaticky posouvá vpřed po
-- archivaci podání DPH (TaxSubmissionArchiver, VAT-lock/H7) a ručně ho posouvá admin
-- (PeriodLockAction). NULL = žádný zámek (default, beze změny chování).
--
-- Aditivní, idempotentní (ADD COLUMN IF NOT EXISTS — MariaDB 10.6+/11.8 native).

SET NAMES utf8mb4;

ALTER TABLE accounting_supplier_settings
  ADD COLUMN IF NOT EXISTS locked_until DATE NULL
    COMMENT 'B8 soft-close: doklady s entry_date <= locked_until nelze běžně zaúčtovat/re-postnout; NULL = bez zámku';
