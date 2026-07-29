-- MyÚčto.cz — Epic DP (daň z příjmů finálně, issue #18): perzistence přiznání
-- DPPO/DPFO + ruční vstupy, daňová uznatelnost nákladů na osnově, nemocenské OSVČ.
--
-- Aditivní migrace (upstream drží 0125+, MyÚčto od 1000_). Idempotentní přes
-- native IF [NOT] EXISTS (MariaDB 10.5.2+). Styl dle 1027/1028: SET NAMES utf8mb4,
-- InnoDB, utf8mb4_unicode_ci, supplier_id INT UNSIGNED + FK na supplier(id).
--
-- Obsah:
--   1. income_tax_returns — hlavička přiznání (rok, typ FO/PO, draft/final) + ruční
--      vstupy (inputs JSON) + snapshot vypočtených řádků (computed JSON) při finalize.
--   2. chart_of_accounts.tax_deductibility — daňová (ne)uznatelnost nákladu dle §25 ZDP,
--      s UPDATE seedem nedaňových syntetik (analytiky dědí přes LEFT(code,3)).
--   3. tax_profiles.sickness_* — dobrovolné nemocenské pojištění OSVČ.

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. income_tax_returns — přiznání k dani z příjmů (hlavička + ruční vstupy)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS income_tax_returns (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  year                SMALLINT UNSIGNED NOT NULL,
  taxpayer_type       ENUM('fo','po') NOT NULL,
  status              ENUM('draft','final') NOT NULL DEFAULT 'draft',
  inputs              JSON NOT NULL DEFAULT ('{}') COMMENT 'ruční vstupy per sekce (§23 úpravy, ztráta, dary, zálohy, §6/§8/§9/§10…)',
  computed            JSON NULL COMMENT 'snapshot vypočtených řádků formuláře při finalize',
  last_submission_id  INT UNSIGNED NULL COMMENT 'FK tax_submissions — poslední export XML',
  row_version         INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'optimistická konkurence draftu',
  created_by          INT UNSIGNED NULL COMMENT 'users.id',
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_itr (supplier_id, year, taxpayer_type),
  KEY idx_itr_supplier (supplier_id),
  CONSTRAINT fk_itr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_itr_submission FOREIGN KEY (last_submission_id) REFERENCES tax_submissions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. chart_of_accounts.tax_deductibility — daňová uznatelnost nákladů (§25 ZDP)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE chart_of_accounts
  ADD COLUMN IF NOT EXISTS tax_deductibility ENUM('deductible','non_deductible')
    NOT NULL DEFAULT 'deductible'
    COMMENT 'daňová uznatelnost nákladu dle §24/§25 ZDP; analytiky dědí ze syntetiky';

-- Seed nedaňových syntetik dle §25 ZDP (a jejich analytik přes LEFT(code,3)):
--   513 reprezentace (§25/1/t), 528 ostatní sociální náklady, 543 dary (§25/1/t;
--   uplatní se přes §20/§15, ne jako náklad), 545 ostatní pokuty a penále (§25/1/f),
--   549 manka a škody nad náhrady (§25/2), 554 tvorba ostatních (účetních) rezerv,
--   559 tvorba účetních opravných položek. Odpisy 551 a daň 59x se NEflagují —
--   mají vlastní mechaniku (ř. 50/150 rozdíl odpisů; 59x se do VH nepočítá).
UPDATE chart_of_accounts
   SET tax_deductibility = 'non_deductible'
 WHERE account_type = 'expense'
   AND LEFT(account_code, 3) IN ('513','528','543','545','549','554','559');

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. tax_profiles.sickness_* — dobrovolné nemocenské pojištění OSVČ
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE tax_profiles
  ADD COLUMN IF NOT EXISTS sickness_insured TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'OSVČ dobrovolně účastna nemocenského pojištění',
  ADD COLUMN IF NOT EXISTS sickness_monthly_base INT UNSIGNED NULL
    COMMENT 'zvolený měsíční vyměřovací základ nemocenského; NULL = zákonné minimum';
