-- MyÚčto.cz — účet na ŘÁDKU přijaté faktury (nad rámec druhu výdaje) + cíl pravidla.
--
-- PROČ: `expense_kind` (1092) říká, CO ta věc je — a to je potřeba pro evidenci drobného
-- majetku a sestavy. Neříká ale, KAM se to účtuje, a to nejsou totéž. Nález z ostrých dat:
--
--   Účetní má na 548.100 „Ostatní provozní náklady" většinu ročního objemu v POJISTNÉM
--   (pojištění odpovědnosti a další pojistné smlouvy). Nám pojistné padalo do 518.
--
--   A má pravdu ona: vyhláška 500/2002 řadí pojistné na **F.5. Jiné provozní náklady** (548),
--   kdežto 518 je **A.3. Služby**. Jsou to různé řádky VZZ — tedy TÝŽ druh chyby jako tablet
--   ve službách (§DM), jen na jiném účtu. VZZ jde do sbírky listin.
--
-- Pojistné je druhem výdaje pořád SLUŽBA (není to materiál ani majetek), takže rozšiřovat kvůli
-- němu `expense_kind` by bylo míchání dvou různých otázek do jednoho sloupce. Správně jsou to
-- dvě osy:
--   `expense_kind`         = CO to je   → evidence, sestavy, práh §26/2 ZDP
--   `expense_account_code` = KAM to jde → účtování; NULL = odvoď z druhu (dnešní chování)
--
-- Precedent pro účet na řádku už v datech je: `purchase_invoice_vat_allocations.account_code`
-- (1073) dělá totéž pro alokace DPH.
--
-- FK ZÁMĚRNĚ NENÍ: `chart_of_accounts` má UNIQUE (supplier_id, account_code), ale
-- `purchase_invoice_items` sloupec `supplier_id` nemá (tenant se dědí přes hlavičku), takže
-- složený FK sestavit nejde. Platnost účtu proto vynucuje PostingService — stejně jako u
-- `debit_account_code` override, a `resolveLines()` na neznámý účet stejně hlasitě spadne
-- (`unknown_account`), takže tichý průchod nehrozí.

SET NAMES utf8mb4;

ALTER TABLE purchase_invoice_items
  ADD COLUMN expense_account_code VARCHAR(10) NULL
      COMMENT 'účet nákladu pro tento řádek; NULL = odvodí se z expense_kind přes posting_rules'
      AFTER expense_kind;

-- Pravidlo umí nově cílit i na účet, ne jen na druh výdaje — bez toho by „Allianz + pojištění
-- → 548" nešlo zautomatizovat a musel by to každý klikat ručně u každé faktury.
-- NULL = pravidlo určuje jen druh a účet se odvodí (dosavadní chování).
ALTER TABLE expense_classification_rules
  ADD COLUMN target_account_code VARCHAR(10) NULL
      COMMENT 'účet nákladu; NULL = odvodí se z expense_kind'
      AFTER expense_kind;
