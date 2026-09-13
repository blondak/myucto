-- MyÚčto.cz - cenové hladiny odběratelů (Bronze / Silver / Gold).
--
-- Hladina je číselník firmy s výchozí slevou v %. Pravidla hladiny zpřesňují slevu pro
-- produkt, kategorii nebo výrobce; jen produkt smí mít pevnou cenu, a to vždy v konkrétní
-- měně. O použití rozhoduje jen EffectivePriceResolver: individuální cena zákazníka (1832)
-- má přednost, hladina nahrazuje standardní cenu jako základ a akční cena se použije jen
-- tehdy, když je levnější než tento základ.
--
-- `clients.price_level_id` je záměrně bez cizího klíče: hladina musí patřit stejné firmě
-- jako odběratel a to hlídá aplikace. Odběratel bez hladiny (NULL) se nacení přesně jako
-- dřív; UI ho ukazuje jako hladinu „Default".

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stock_price_levels (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  default_discount_pct DECIMAL(6,3) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  display_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_spl_code (supplier_id, code),
  UNIQUE KEY uq_spl_supplier_id (supplier_id, id),
  CONSTRAINT fk_spl_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT chk_spl_default_discount CHECK (default_discount_pct >= 0 AND default_discount_pct <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- currency_code NULL = pravidlo platí ve všech měnách (jen sleva). Unikátní klíč s NULL
-- duplicitu slevy bez měny nezachytí; tu odmítá aplikace při ukládání sady pravidel.
CREATE TABLE IF NOT EXISTS stock_price_level_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  price_level_id BIGINT UNSIGNED NOT NULL,
  match_type ENUM('product','category','manufacturer') NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  rule_type ENUM('discount_pct','fixed') NOT NULL,
  discount_pct DECIMAL(6,3) NULL,
  fixed_price DECIMAL(12,2) NULL,
  currency_code CHAR(3) NULL,
  priority INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_splr_match (supplier_id, price_level_id, match_type, match_id, currency_code),
  KEY ix_splr_match (supplier_id, match_type, match_id),
  CONSTRAINT fk_splr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_splr_level FOREIGN KEY (supplier_id, price_level_id)
    REFERENCES stock_price_levels(supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT chk_splr_value CHECK (
    (rule_type = 'fixed' AND match_type = 'product' AND fixed_price IS NOT NULL AND fixed_price >= 0
      AND currency_code IS NOT NULL)
    OR (rule_type = 'discount_pct' AND discount_pct IS NOT NULL AND discount_pct >= 0 AND discount_pct <= 100)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS price_level_id BIGINT UNSIGNED NULL;
ALTER TABLE clients
  ADD KEY IF NOT EXISTS ix_clients_price_level (supplier_id, price_level_id);
