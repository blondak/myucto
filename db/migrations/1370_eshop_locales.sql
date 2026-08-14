-- 1370: číselník jazykových mutací e-shopu (stock_locales)
--
-- Karta zboží nabízela na tabu Jazyky pevný seznam pěti locale zadrátovaný ve
-- frontendu (`AVAILABLE_LOCALES = ['cs','en','sk','de','pl']` v ItemEditor.vue).
-- Firma prodávající do Maďarska tam maďarštinu nedostala a naopak každá firma
-- viděla čtyři jazyky, které nevede. Jazyky proto dostávají vlastní číselník
-- per supplier — stejný vzor jako výrobci, tagy nebo poplatky (1028).
--
-- Prefix `stock_` je záměrný: všechny satelitní číselníky Epicu ESHOP nad
-- stock_items ho mají (stock_tags, stock_fee_types, stock_attributes).
--
-- Backfill je dvoustupňový, aby po migraci NEZMIZEL žádný existující překlad:
--   1) do číselníku se doplní každý locale, který se reálně vyskytuje
--      v stock_item_i18n nebo stock_category_i18n. Zápisová cesta locale proti
--      číselníku validuje, takže bez tohohle kroku by uživatel nemohl uložit
--      kartu, kterou má dávno přeloženou.
--   2) firmám se zapnutým skladem, které zatím žádný překlad nemají, se založí
--      čeština, ať číselník není při prvním otevření prázdný. Firmě, která už
--      překlady má, se nic nepřidává — jazyky si vede sama.
-- Výchozí jazyk (is_default) dostane firma, která žádný nemá: čeština, jinak
-- abecedně první. is_default je jen předvyplnění na kartě, ne tvrdé pravidlo.
--
-- Re-run safe: CREATE TABLE IF NOT EXISTS + INSERT IGNORE; UPDATE výchozího
-- jazyka po prvním běhu žádný řádek nenajde (SUM(is_default) je pak > 0).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stock_locales (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  code VARCHAR(5) NOT NULL COMMENT 'cs, en, de, pt-BR — shodné s stock_item_i18n.locale',
  name VARCHAR(100) NOT NULL COMMENT 'název jazyka pro UI (Čeština, English)',
  display_order INT NOT NULL DEFAULT 0,
  is_default TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'předvyplněný jazyk na kartě',
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sloc_supplier_code (supplier_id, code),
  KEY idx_sloc_supplier (supplier_id, archived, display_order),
  -- `sloc`, ne `sl` — jména FK jsou v InnoDB globální per DB a fk_sl_supplier
  -- si už vzaly stock_levels (1022).
  CONSTRAINT fk_sloc_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1) Locale, které už mají překlad — jinak by je zápisová validace odmítla.
INSERT IGNORE INTO stock_locales (supplier_id, code, name, display_order, is_default)
SELECT u.supplier_id,
       u.locale,
       CASE LOWER(LEFT(u.locale, 2))
         WHEN 'cs' THEN 'Čeština'
         WHEN 'sk' THEN 'Slovenčina'
         WHEN 'en' THEN 'English'
         WHEN 'de' THEN 'Deutsch'
         WHEN 'pl' THEN 'Polski'
         WHEN 'hu' THEN 'Magyar'
         WHEN 'fr' THEN 'Français'
         WHEN 'es' THEN 'Español'
         WHEN 'it' THEN 'Italiano'
         WHEN 'uk' THEN 'Українська'
         ELSE UPPER(u.locale)
       END,
       0,
       0
  FROM (
        SELECT supplier_id, locale FROM stock_item_i18n
         WHERE locale IS NOT NULL AND locale <> ''
        UNION
        SELECT supplier_id, locale FROM stock_category_i18n
         WHERE locale IS NOT NULL AND locale <> ''
       ) u;

-- 2) Firmy se zapnutým skladem, které zatím nemají žádný jazyk.
INSERT IGNORE INTO stock_locales (supplier_id, code, name, display_order, is_default)
SELECT s.id, 'cs', 'Čeština', 0, 1
  FROM supplier s
 WHERE s.stock_enabled = 1
   AND NOT EXISTS (SELECT 1 FROM stock_locales sl WHERE sl.supplier_id = s.id);

-- 3) Výchozí jazyk firmám, které po backfillu žádný nemají.
UPDATE stock_locales sl
  JOIN (
        SELECT r.id
          FROM (
                SELECT id,
                       ROW_NUMBER() OVER (
                         PARTITION BY supplier_id
                         ORDER BY CASE WHEN code = 'cs' THEN 0 ELSE 1 END, display_order, code
                       ) AS rn,
                       SUM(is_default) OVER (PARTITION BY supplier_id) AS defaults
                  FROM stock_locales
               ) r
         WHERE r.rn = 1 AND r.defaults = 0
       ) pick ON pick.id = sl.id
   SET sl.is_default = 1;
