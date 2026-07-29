-- MyÚčto.cz — „Typ úhrady" na přijaté faktuře (hlavně kvůli INKASU).
--
-- Motivace: stránka platebních příkazů nabízí k úhradě VŠECHNY nezaplacené přijaté
-- faktury. U inkasa (direct debit / SIPO) si ale peníze strhne dodavatel sám — příkaz
-- k úhradě by znamenal DVOJÍ platbu. Doklad přitom nese číslo účtu, VS i KS úplně
-- stejně jako převodní faktura, takže z platebních údajů to nepoznáme; typ úhrady musí
-- být samostatný atribut dokladu.
--
--   purchase_invoices.payment_method        = forma úhrady dokladu (default bank_transfer)
--   purchase_invoices.payment_method_source = KDO hodnotu nastavil (priorita při přepisu:
--                                             manual > ai > vendor > default; AI ani
--                                             dodavatel NIKDY nepřepíšou 'manual')
--   clients.default_payment_method          = předvolba u dodavatele; NULL = nemá názor
--                                             (např. „ČEZ platíme vždy inkasem")
--
-- ENUM u vydaných `invoices.payment_method` se rozšiřuje na stejnou sadu hodnot, ať se
-- obě strany nerozcházejí (mapování v exportech je sdílené).
--
-- Idempotence: ADD COLUMN IF NOT EXISTS (vzor 1127), MODIFY je samo o sobě idempotentní,
-- index gate-ovaný přes information_schema. Migrace je bezpečně opakovatelná.
--
-- Aditivní a bezpečná i na ostrých datech: všechny existující řádky dostanou default
-- 'bank_transfer' + source 'default', což je přesně dosavadní (implicitní) chování.

SET NAMES utf8mb4;
SET @@system_versioning_alter_history = 1;

-- 1) Forma úhrady na přijaté faktuře + zdroj hodnoty.
ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS payment_method
    ENUM('bank_transfer','direct_debit','card','cash','cash_on_delivery','offset','other')
    NOT NULL DEFAULT 'bank_transfer'
    COMMENT 'forma úhrady dokladu; direct_debit (inkaso/SIPO) = NEtvořit platební příkaz'
    AFTER payment_constant_symbol;

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS payment_method_source
    ENUM('default','vendor','ai','manual') NOT NULL DEFAULT 'default'
    COMMENT 'kdo payment_method nastavil; priorita manual > ai > vendor > default'
    AFTER payment_method;

-- 2) Předvolba u dodavatele. NULL = dodavatel „nemá názor" a doklad si drží default.
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS default_payment_method
    ENUM('bank_transfer','direct_debit','card','cash','cash_on_delivery','offset','other')
    NULL DEFAULT NULL
    COMMENT 'předvolená forma úhrady faktur od tohoto dodavatele; NULL = neurčeno';

-- 3) Rozšíření ENUMu u vydaných faktur na stejnou sadu hodnot (dosud jen
--    bank_transfer/card/cash/other). Čistě rozšíření domény — žádná existující
--    hodnota se nemění a MODIFY drží NOT NULL DEFAULT 'bank_transfer'.
ALTER TABLE invoices
  MODIFY COLUMN payment_method
    ENUM('bank_transfer','direct_debit','card','cash','cash_on_delivery','offset','other')
    NOT NULL DEFAULT 'bank_transfer';

-- 4) Index pro filtr kandidátů platebního příkazu (WHERE supplier_id = ? AND
--    payment_method = 'bank_transfer'). MariaDB nemá CREATE INDEX IF NOT EXISTS
--    napříč verzemi spolehlivě → gate přes information_schema + prepared statement.
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE()
                AND table_name = 'purchase_invoices'
                AND index_name = 'idx_pi_payment_method');
SET @sql := IF(@idx = 0,
  'CREATE INDEX idx_pi_payment_method ON purchase_invoices (supplier_id, payment_method)',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
