-- MyÚčto.cz — evidence pro úpravu odpočtu daně u dlouhodobého majetku (§ 78–78e ZDPH).
--
-- PROČ: systém sledování 5/10leté lhůty neměl vůbec — atribut `uprav_odp` (ř. 60
-- přiznání) měl v celém repozitáři NULA výskytů. U nemovitosti nebo majetku pořízeného
-- s kráceným nárokem (§ 76) je přitom úprava odpočtu POVINNÁ po celou lhůtu a nikdo
-- na ni neupozornil ani ji nespočítal. Poplatník na ni musel přijít sám.
--
-- Co se eviduje: majetek, u kterého byl uplatněn odpočet, spolu s poměrem, v jakém se
-- uplatnil. V dalších letech lhůty se aktuální poměr porovná s původním a při odchylce
-- nad 10 procentních bodů (§ 78a odst. 3) vznikne úprava = 1/N × původní daň × rozdíl.
--
-- LHŮTA (§ 78 odst. 3): 5 let u movitých věcí, 10 let u staveb, jednotek a jejich
-- technického zhodnocení a u pozemků. Ukládá se jako počet let, ne jako typ majetku —
-- délku určuje účetní při zařazení a novela ji může změnit, aniž bychom migrovali data.
--
-- ZNAMÉNKO: úprava může být kladná i záporná (XSD anotace `uprav_odp`), protože poměr
-- použití se může posunout oběma směry.
--
-- Idempotentní: CREATE TABLE IF NOT EXISTS + ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS vat_deduction_adjustments (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  purchase_invoice_id  BIGINT UNSIGNED NULL COMMENT 'doklad, kterým byl majetek pořízen (zdroj původní daně)',
  asset_id             BIGINT UNSIGNED NULL COMMENT 'karta majetku, je-li vedena',
  label                VARCHAR(190) NOT NULL COMMENT 'označení majetku pro rozpis v přiznání',
  acquired_on          DATE NOT NULL COMMENT 'pořízení / zařazení — od něj běží lhůta',
  period_years         TINYINT UNSIGNED NOT NULL COMMENT '5 movité, 10 stavby/jednotky/pozemky (§78/3)',
  original_vat         DECIMAL(14,2) NOT NULL COMMENT 'daň na vstupu u pořízení, v CZK',
  original_ratio_pct   TINYINT UNSIGNED NOT NULL COMMENT 'poměr, v jakém byl odpočet uplatněn (0-100)',
  note                 VARCHAR(255) NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_vda_supplier_acquired (supplier_id, acquired_on),
  CONSTRAINT fk_vda_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT chk_vda_period CHECK (period_years IN (5, 10)),
  CONSTRAINT chk_vda_ratio CHECK (original_ratio_pct BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provedené úpravy per rok — aby se táž úprava nezapočítala dvakrát a aby šlo dohledat,
-- co se v kterém přiznání uvedlo.
CREATE TABLE IF NOT EXISTS vat_deduction_adjustment_years (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  adjustment_id        BIGINT UNSIGNED NOT NULL,
  supplier_id          INT UNSIGNED NOT NULL,
  year                 SMALLINT UNSIGNED NOT NULL,
  current_ratio_pct    TINYINT UNSIGNED NOT NULL COMMENT 'poměr použití v tomto roce',
  amount               DECIMAL(14,2) NOT NULL COMMENT 'úprava za rok; kladná i záporná',
  settled_at           TIMESTAMP NULL COMMENT 'vyplněno, jakmile se rok uvedl v přiznání',
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_vday_adjustment_year (adjustment_id, year),
  KEY idx_vday_supplier_year (supplier_id, year),
  CONSTRAINT fk_vday_adjustment FOREIGN KEY (adjustment_id) REFERENCES vat_deduction_adjustments(id) ON DELETE CASCADE,
  CONSTRAINT fk_vday_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT chk_vday_ratio CHECK (current_ratio_pct BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
