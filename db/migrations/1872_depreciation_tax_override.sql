-- MyÚčto.cz — ruční přepis daňového odpisu roku na kartě majetku
--
-- Daňový odpis roku lze ručně nastavit na jinou částku, než spočítala kalkulačka
-- nebo převzal převod (typicky „převzít čísla účetní", která počítala mimo program).
-- Důvod je povinný; řádek si drží původní částku a zůstatkovou cenu, aby šel přepis
-- vrátit, a kdo a kdy ho zadal. Každou změnu navíc zapisuje protokol činností.
-- Hromadné potvrzení odpisů ani opakovaný převod ručně přepsaný řádek nemění.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE depreciation_entries
  ADD COLUMN IF NOT EXISTS override_reason VARCHAR(500) NULL
      COMMENT 'ruční přepis daňového odpisu roku: důvod; NULL = odpis podle kalkulačky nebo převodu'
      AFTER status,
  ADD COLUMN IF NOT EXISTS override_original_amount DECIMAL(14,2) NULL
      COMMENT 'uplatněný odpis před ručním přepisem'
      AFTER override_reason,
  ADD COLUMN IF NOT EXISTS override_original_full_amount DECIMAL(14,2) NULL
      COMMENT 'stanovený odpis před ručním přepisem'
      AFTER override_original_amount,
  ADD COLUMN IF NOT EXISTS override_original_residual DECIMAL(14,2) NULL
      COMMENT 'daňová zůstatková cena na konci roku před ručním přepisem'
      AFTER override_original_full_amount,
  ADD COLUMN IF NOT EXISTS override_by INT NULL
      COMMENT 'uživatel, který odpis ručně přepsal'
      AFTER override_original_residual,
  ADD COLUMN IF NOT EXISTS override_at DATETIME NULL
      COMMENT 'okamžik ručního přepisu'
      AFTER override_by;
