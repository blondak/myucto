-- 1371: číselník prodejních měn e-shopu (stock_currencies)
--
-- Ceny na kartě zboží braly nabídku měn z `currencies`, což jsou ale MĚNOVÉ ÚČTY
-- dodavatele (číslo účtu, IBAN, BIC) — tedy účetní a platební rovina. E-shop je
-- jiná úloha: prodejní měna je prezentace ceny zákazníkovi a s tím, kde nám peníze
-- přistanou, nesouvisí. Zboží lze nacenit v GBP a zákazník zaplatí kartou na eurový
-- účet; s vazbou na `currencies` by GBP v nabídce nikdy nebylo, protože nemáme
-- britský účet.
--
-- Číselník je proto samostatný, per supplier, a záměrně BEZ FK na `currencies`
-- i bez vazby na účetnictví. Cenové tabulky drží `currency_code CHAR(3)` jako
-- volný ISO 4217 kód (stock_item_prices, stock_item_promo_prices, stock_item_fees),
-- takže se tu nic nepřepisuje — mění se jen to, co UI nabídne.
--
-- Prefix `stock_` drží konvenci ostatních satelitních číselníků Epicu ESHOP
-- (stock_locales, stock_tags, stock_fee_types, stock_attributes).
--
-- Backfill je třístupňový, aby po migraci nezmizela žádná existující cena:
--   1) každá měna reálně použitá v cenách, akčních cenách nebo poplatcích,
--   2) CZK pro každou firmu se skladem — výchozí měna e-shopu je vždy koruna,
--   3) is_default na CZK; firmě bez CZK (teoreticky) první podle pořadí.
--
-- Re-run safe: CREATE TABLE IF NOT EXISTS + INSERT IGNORE; UPDATE výchozí měny
-- po prvním běhu žádný řádek nenajde (SUM(is_default) je pak > 0).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stock_currencies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  code CHAR(3) NOT NULL COMMENT 'ISO 4217 — shodné s stock_item_prices.currency_code',
  name VARCHAR(100) NOT NULL COMMENT 'název měny pro UI (Česká koruna, Euro)',
  symbol VARCHAR(8) NULL COMMENT 'zobrazovaný symbol (Kč, €, £)',
  display_order INT NOT NULL DEFAULT 0,
  is_default TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'předvyplněná měna nového cenového řádku',
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_scur_supplier_code (supplier_id, code),
  KEY idx_scur_supplier (supplier_id, archived, display_order),
  -- `scur`, ne `sc`: jména FK jsou v InnoDB globální per DB a krátké prefixy
  -- si už rozebraly starší skladové tabulky (viz poznámka u 1370).
  CONSTRAINT fk_scur_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1) Měny, ve kterých už nějaká cena existuje — jinak by je nabídka „ztratila".
INSERT IGNORE INTO stock_currencies (supplier_id, code, name, symbol, display_order, is_default)
SELECT u.supplier_id,
       UPPER(u.currency_code),
       CASE UPPER(u.currency_code)
         WHEN 'CZK' THEN 'Česká koruna'
         WHEN 'EUR' THEN 'Euro'
         WHEN 'USD' THEN 'Americký dolar'
         WHEN 'GBP' THEN 'Britská libra'
         WHEN 'PLN' THEN 'Polský zlotý'
         WHEN 'HUF' THEN 'Maďarský forint'
         WHEN 'CHF' THEN 'Švýcarský frank'
         WHEN 'RON' THEN 'Rumunský lei'
         WHEN 'BGN' THEN 'Bulharský lev'
         WHEN 'SEK' THEN 'Švédská koruna'
         WHEN 'DKK' THEN 'Dánská koruna'
         WHEN 'NOK' THEN 'Norská koruna'
         ELSE UPPER(u.currency_code)
       END,
       CASE UPPER(u.currency_code)
         WHEN 'CZK' THEN 'Kč'
         WHEN 'EUR' THEN '€'
         WHEN 'USD' THEN '$'
         WHEN 'GBP' THEN '£'
         WHEN 'PLN' THEN 'zł'
         WHEN 'HUF' THEN 'Ft'
         WHEN 'CHF' THEN 'CHF'
         WHEN 'SEK' THEN 'kr'
         WHEN 'DKK' THEN 'kr'
         WHEN 'NOK' THEN 'kr'
         ELSE NULL
       END,
       0,
       0
  FROM (
        SELECT supplier_id, currency_code FROM stock_item_prices
         WHERE currency_code IS NOT NULL AND currency_code <> ''
        UNION
        SELECT supplier_id, currency_code FROM stock_item_promo_prices
         WHERE currency_code IS NOT NULL AND currency_code <> ''
        UNION
        SELECT supplier_id, currency_code FROM stock_item_fees
         WHERE currency_code IS NOT NULL AND currency_code <> ''
       ) u;

-- 2) CZK má v číselníku každá firma se skladem — výchozí měna e-shopu je vždy koruna.
INSERT IGNORE INTO stock_currencies (supplier_id, code, name, symbol, display_order, is_default)
SELECT s.id, 'CZK', 'Česká koruna', 'Kč', 0, 1
  FROM supplier s
 WHERE s.stock_enabled = 1;

-- 3) Výchozí měna firmám, které po backfillu žádnou nemají: CZK, jinak první v pořadí.
UPDATE stock_currencies sc
  JOIN (
        SELECT r.id
          FROM (
                SELECT id,
                       ROW_NUMBER() OVER (
                         PARTITION BY supplier_id
                         ORDER BY CASE WHEN code = 'CZK' THEN 0 ELSE 1 END, display_order, code
                       ) AS rn,
                       SUM(is_default) OVER (PARTITION BY supplier_id) AS defaults
                  FROM stock_currencies
               ) r
         WHERE r.rn = 1 AND r.defaults = 0
       ) pick ON pick.id = sc.id
   SET sc.is_default = 1;
