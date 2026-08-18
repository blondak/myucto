-- MyÚčto.cz — volitelná vlastní číselná řada pokladny (L-3, audit pokladny 2026-08)
--
-- Řady PPD/VPD byly per firma, takže firma se dvěma pokladnami měla číslování
-- prokládané napříč knihami a kniha jedné pokladny vypadala děravě. Řada teď umí
-- být i per pokladna — VOLITELNĚ, `cash_registers.own_series` je defaultně 0, takže
-- se stávajícím firmám nemění nic a žádný existující doklad se nepřečísluje.
--
-- `register_id` je NOT NULL DEFAULT 0 (ne NULL), protože unikátní klíč s NULL sloupcem
-- nehlídá nic — MariaDB by pustila libovolný počet řádků se společnou řadou.
-- 0 = společná řada firmy (dosavadní chování), >0 = řada konkrétní pokladny.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS a IF NOT EXISTS / IF EXISTS u indexů. Nový
-- index vzniká PŘED zahozením starého: oba mají supplier_id vlevo a InnoDB jeden
-- z nich potřebuje pro `fk_ads_supplier` (jinak 1553 „needed in a foreign key
-- constraint"), takže firma nesmí zůstat ani na okamžik bez indexu.

SET NAMES utf8mb4;

ALTER TABLE accounting_document_series
  ADD COLUMN IF NOT EXISTS register_id INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT '0 = společná řada firmy; >0 = vlastní řada pokladny (cash_registers.id)'
    AFTER series_code;

CREATE UNIQUE INDEX IF NOT EXISTS uq_ads_supplier_series_year_register
  ON accounting_document_series (supplier_id, series_code, fiscal_year, register_id);
DROP INDEX IF EXISTS uq_ads_supplier_series_year ON accounting_document_series;

ALTER TABLE cash_registers
  ADD COLUMN IF NOT EXISTS own_series TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Pokladna má vlastní číselnou řadu PPD/VPD (L-3); 0 = společná řada firmy'
    AFTER is_default;
