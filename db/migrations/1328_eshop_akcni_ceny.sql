-- MyÚčto.cz — akční (promoční) ceny zboží v e-shopu.
--
-- PROBLÉM: cenotvorba (`stock_item_prices`, migrace Epicu ESHOP) umí jen JEDNU platnou
-- cenu per karta a měna. Časově omezená akce se dosud dělala tak, že se řádek přepnul na
-- „Fixní cena + Ruční" a po skončení akce se ručně vrátil zpátky (manuál § 34.10.5).
-- Nic ten návrat nepřipomnělo, takže akční cena běžně platila dál a nikdo nevěděl,
-- jaká byla původní hladina.
--
-- ŘEŠENÍ: samostatná tabulka akčních cen NAD standardní cenou. Standardní cena zůstává
-- beze změny (přirážka/přepočet běží dál), akce je jen dočasný override, který se sám
-- vypne. Tři NEZÁVISLÉ, každý VOLITELNÝ limit:
--
--   1) ČASOVÉ OKNO — `valid_from` / `valid_to`, obojí NULL = bez omezení.
--   2) MNOŽSTEVNÍ STROP — `qty_mode`:
--        'stock'     (VÝCHOZÍ) … akce platí, dokud je zboží skladem, a nejvýš na tolik
--                                kusů, kolik je právě na skladě. Strop se NEODEČÍTÁ —
--                                čte se živě ze `stock_levels`. Doskladnění akci zase
--                                „nabije" (= české „do vyprodání zásob").
--        'limited'   … pevný rozpočet `qty_limit` kusů („prvních 100 ks"). Odečítá se
--                                prodejem a doskladnění ho NEobnoví.
--        'unlimited' … bez množstevního stropu.
--   3) SAMA AKČNÍ CENA — `promo_price` (bez DPH, v měně řádku).
--
-- ── Proč se čerpání NEEVIDUJE v samostatné tabulce ──────────────────────────────────
-- Vyčerpané množství u režimu 'limited' se DOPOČÍTÁVÁ z vystavených faktur
-- (`EffectivePriceResolver` / `StockItemPromoPriceRepository::consumedQty`), ne ze
-- vlastního čítače. Čítač by musel mít háček ve všech třech cestách vystavení faktury
-- + ve stornu, mazání a dobropisu, a při jakémkoli minutí by tiše driftoval bez šance
-- na rekonciliaci. Odvození ze zdroje pravdy (řádky faktur) se samo opraví — storno,
-- smazání i dobropis rozpočet vrátí bez jediného háčku.
--
-- Okno počítání čerpání = COALESCE(valid_from, DATE(created_at)). U akce bez `valid_from`
-- by se jinak do rozpočtu započítaly i prodeje z doby PŘED jejím založením.
--
-- ── Překryv akcí ────────────────────────────────────────────────────────────────────
-- Víc současně platných akcí na téže kartě a měně je POVOLENO (schválně žádný unique
-- index přes okno) — vrství se sezónní a produktové kampaně. Vyhrává NEJNIŽŠÍ cena,
-- při shodě novější záznam (vyšší id). Zákazník tak vždy dostane nejlepší inzerovanou
-- cenu a výsledek je deterministický.

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS `stock_item_promo_prices` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_id` INT(10) UNSIGNED NOT NULL,
  `stock_item_id` BIGINT(20) UNSIGNED NOT NULL,
  `currency_code` CHAR(3) NOT NULL COMMENT 'ISO 4217; váže se na řádek stock_item_prices téže měny',
  `promo_price` DECIMAL(12,2) NOT NULL COMMENT 'akční cena bez DPH v dané měně',
  `label` VARCHAR(60) DEFAULT NULL COMMENT 'název akce (Černý pátek…) — jen popisný',
  `valid_from` DATE DEFAULT NULL COMMENT 'NULL = bez omezení zdola',
  `valid_to` DATE DEFAULT NULL COMMENT 'NULL = bez omezení shora (včetně tohoto dne)',
  `qty_mode` ENUM('stock','limited','unlimited') NOT NULL DEFAULT 'stock'
      COMMENT 'stock = do vyprodání zásob (živě), limited = pevný rozpočet qty_limit, unlimited = bez stropu',
  `qty_limit` DECIMAL(14,3) DEFAULT NULL COMMENT 'jen pro qty_mode=limited: kolik kusů akce celkem pokryje',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = akce vypnutá bez mazání historie',
  `note` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp()
      COMMENT 'náhradní začátek okna čerpání, když valid_from chybí',
  `updated_at` TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sipp_lookup` (`supplier_id`, `stock_item_id`, `currency_code`, `is_active`),
  KEY `idx_sipp_window` (`supplier_id`, `valid_from`, `valid_to`),
  CONSTRAINT `fk_sipp_item` FOREIGN KEY (`stock_item_id`) REFERENCES `stock_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sipp_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_sipp_qty_limit` CHECK (
      (`qty_mode` = 'limited' AND `qty_limit` IS NOT NULL AND `qty_limit` > 0)
      OR (`qty_mode` <> 'limited' AND `qty_limit` IS NULL)
  ),
  CONSTRAINT `chk_sipp_window` CHECK (
      `valid_from` IS NULL OR `valid_to` IS NULL OR `valid_to` >= `valid_from`
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Akční ceny karty zboží (e-shop) — dočasný override nad stock_item_prices';

-- Dopočet vyčerpaného množství (qty_mode='limited') sčítá řádky faktur per karta
-- a datum plnění. `invoice_items.stock_item_id` index má, ale join na `invoices`
-- pak filtruje podle supplier_id + typu + data — složený index to zvládne bez
-- filesortu i u firmy s desítkami tisíc dokladů.
ALTER TABLE `invoices`
  ADD KEY IF NOT EXISTS `idx_inv_promo_consumption` (`supplier_id`, `invoice_type`, `status`, `tax_date`);
