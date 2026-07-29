-- MyÚčto.cz — prodej majetku: vazba karty na VYDANOU fakturu + volba výnosového účtu.
--
-- PROČ: prodej evidovaného majetku dnes nikde neuzavře kartu. Účetní vystaví vydanou
-- fakturu (tržba + DPH), ale karta drobného i dlouhodobého majetku o tom neví — v soupisu
-- k inventarizaci (§28/5 ZoÚ) pak visí věc, kterou už firma nemá. Tahle migrace přidává
-- na karty vazbu na doklad prodeje a stav „prodáno".
--
-- DVA DRUHY MAJETKU, DVA ÚČTY VÝNOSU:
--   • Drobný majetek (small_assets): prodej = běžná vydaná faktura, výnos 602/604 + DPH,
--     zůstatková cena je 0 (náklad na 501 padl už při pořízení). Karta jen dostane stav
--     'sold' + vazbu na fakturu; NIC se z ní neúčtuje.
--   • Dlouhodobý majetek (assets): tržba 311/641 + DPH (rule asset.sale.revenue) a
--     zůstatková cena se doúčtuje 541/08x — to už umí AssetService::dispose(type='sold').
--     Tady jen ukládáme na kartu odkaz na fakturu prodeje.
--
-- VÝNOSOVÝ ÚČET NA VYDANÉ FAKTUŘE (invoices.revenue_rule_key): PostingService dnes posílá
-- celý netto vydané faktury na JEDEN výnosový účet z rule_key 'invoice.services.issued'
-- (default 602). Aby prodej dlouhodobého majetku sedl na 641 (a ne 602), dostává hlavička
-- faktury volitelný `revenue_rule_key` — název kontačního pravidla, které buildFromInvoice
-- přemapuje na výnosový (a saldokontní) účet. NULL = dosavadní chování (602), takže žádná
-- stávající faktura se nehne a podvojnost zůstává netknutá. revenue_category_id vedle je
-- jen REPORTNÍ (kategorie tržby), na kontaci nemá vliv — proto samostatný sloupec.
--
-- ON DELETE SET NULL u sale_invoice_id: smazání faktury nesmí shodit kartu majetku, jen ji
-- odpojí (stejný důvod jako u zdrojových dokladů v 1094/1096). Proto taky CHECK „prodáno ⇔
-- prodáno" nepáruje přes sale_invoice_id (sloupec s FK ON DELETE SET NULL by shodil CHECK
-- chybou 1901, viz 1094), ale přes `sold_at`, který FK nemá.
--
-- Idempotence: migrace běží přes migrations bookkeeping tabulku právě jednou; styl (plain
-- ALTER ADD COLUMN + FK) drží konvenci 1096.

SET NAMES utf8mb4;

-- ── drobný majetek ──────────────────────────────────────────────────────────
-- Nejdřív pryč se starým CHECK, který zná jen in_use/disposed — jinak by MODIFY statusu ani
-- nové řádky 'sold' neprošly.
ALTER TABLE small_assets DROP CONSTRAINT chk_sma_disposal;

ALTER TABLE small_assets
  MODIFY COLUMN status ENUM('in_use','disposed','sold') NOT NULL DEFAULT 'in_use'
      COMMENT 'in_use = v užívání; disposed = vyřazeno (likvidace/dar); sold = prodáno vydanou fakturou',
  ADD COLUMN sale_invoice_id BIGINT UNSIGNED NULL
      COMMENT 'vydaná faktura, kterou byla karta prodána; NULL = neprodáno fakturou'
      AFTER disposal_reason,
  ADD COLUMN sold_at DATE NULL
      COMMENT 'datum prodeje; párový příznak stavu sold (CHECK přes něj, ne přes FK sloupec)'
      AFTER sale_invoice_id,
  ADD COLUMN sale_price DECIMAL(14,2) NULL
      COMMENT 'prodejní cena bez DPH — jen evidenční, na 501 se nic nedoúčtovává (ZC=0)'
      AFTER sold_at,
  ADD KEY idx_sma_sale_invoice (sale_invoice_id),
  ADD CONSTRAINT fk_sma_sale_invoice FOREIGN KEY (sale_invoice_id)
      REFERENCES invoices(id) ON DELETE SET NULL;

-- Nový CHECK: tři vzájemně výlučné stavy, každý se svým párovým datem. 'sold' se páruje přes
-- sold_at (ne přes sale_invoice_id, který má FK SET NULL — CHECK by na něm spadl chybou 1901).
ALTER TABLE small_assets
  ADD CONSTRAINT chk_sma_disposal CHECK (
       (status = 'in_use'   AND disposed_at IS NULL     AND sold_at IS NULL)
    OR (status = 'disposed' AND disposed_at IS NOT NULL AND sold_at IS NULL)
    OR (status = 'sold'     AND sold_at     IS NOT NULL AND disposed_at IS NULL)
  ),
  ADD CONSTRAINT chk_sma_sold_after CHECK (sold_at IS NULL OR sold_at >= acquisition_date);

-- ── dlouhodobý majetek ──────────────────────────────────────────────────────
-- Jen odkaz na fakturu prodeje; zůstatkovou cenu i disposal zápis řeší AssetService::dispose.
ALTER TABLE assets
  ADD COLUMN sale_invoice_id BIGINT UNSIGNED NULL
      COMMENT 'vydaná faktura prodeje (type=sold); jen evidenční vazba, tržba se účtuje z faktury (R20)'
      AFTER disposal_price,
  ADD KEY idx_assets_sale_invoice (sale_invoice_id),
  ADD CONSTRAINT fk_assets_sale_invoice FOREIGN KEY (sale_invoice_id)
      REFERENCES invoices(id) ON DELETE SET NULL;

-- ── vydaná faktura: volitelná volba výnosového účtu ─────────────────────────
ALTER TABLE invoices
  ADD COLUMN revenue_rule_key VARCHAR(64) NULL
      COMMENT 'kontační pravidlo výnosu pro buildFromInvoice (např. asset.sale.revenue → 311/641); NULL = default invoice.services.issued (602)'
      AFTER revenue_category_id;
