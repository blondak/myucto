-- MyÚčto.cz — druh výdaje na ŘÁDKU přijaté faktury (§DM, evidence drobného majetku).
--
-- PROČ: nemáme kategorii drobného majetku, takže všechno padá do 518 „Ostatní služby" —
-- účet 501 má u nás ve všech letech 0,00, zatímco účetní na něm za 2025 vede 291 530,04
-- (501.100 materiál + PHM 31 522,84, 501.200 drobný majetek 260 007,20). Není to kosmetika:
-- 501 a 518 jsou RŮZNÉ řádky VZZ (A.2. Spotřeba materiálu a energie × A.3. Služby), a VZZ
-- jde do sbírky listin. Vykazujeme tablet a zařízení kanceláře jako služby.
--
-- PROČ NA POLOŽCE, NE NA HLAVIČCE: faktura běžně míchá majetek i služby — Alza prodá na
-- jednom dokladu notebook (drobný majetek), brašnu (materiál), dopravu a prodlouženou záruku
-- (služby). Hlavičkový příznak to nedokáže rozlišit. `purchase_invoice_items` má proto už
-- dnes `is_fixed_asset` jako „override header pro mixed doklady" (0044) — jenže boolean na
-- 4-cestnou klasifikaci nestačí a PostingService ho stejně nečte.
--
-- KLASIFIKACE JE 4-CESTNÁ, ne 2-cestná — PHM je materiál, ale NENÍ drobný majetek a do jeho
-- evidence nesmí (účetní ho vede na 501.100 vedle 501.200):
--     service      → 518   služby (hosting, telefony, nájem, doprava, záruka)
--     material     → 501   spotřební materiál včetně PHM
--     small_asset  → 501   drobný majetek pod hranicí §26/2 ZDP (evidence dle §28 ZoÚ / ČÚS 013)
--     fixed_asset  → 042   dlouhodobý majetek nad 80 000 (§26/2 ZDP) → zařazení na 02x, odpisy
--
-- NULL = NEURČENO a chová se přesně jako dosud (518). Migrace tím nic nerozbije: všech 446
-- stávajících položek zůstane NULL a jejich zaúčtování je byte-identické. PostingService
-- rozpadá náklad po položkách teprve tehdy, když je aspoň jedna položka klasifikovaná.
--
-- DVA ZDROJE PRAVDY: `is_fixed_asset` ZŮSTÁVÁ, protože ho čte VatLedgerService (ř. 47 DPHDP3
-- — pořízení DM) a AssetRepository. Autoritativní je nově `expense_kind`; repozitář oba drží
-- v souladu (expense_kind='fixed_asset' ⇔ is_fixed_asset=1). Backfill níž je narovná i zpětně.
--
-- ANALYTIKY 501.100/501.200 ZÁMĚRNĚ NEZAVÁDÍME. Naše osnova dělí analytiku podstatou a
-- písmenným sufixem (311D, 559M), ne tečkovým číslováním účetní; syntetika 501 stačí, aby
-- VZZ i obratová předvaha seděly. Rozdíl materiál × drobný majetek nese `expense_kind` a
-- sestavy si ho z něj vytáhnou — bez zásahu do osnovy, který nikdo neschválil.

SET NAMES utf8mb4;

ALTER TABLE purchase_invoice_items
  ADD COLUMN expense_kind ENUM('service','material','small_asset','fixed_asset') NULL
      COMMENT 'druh výdaje pro účtování a evidenci; NULL = neurčeno (chová se jako service/518)'
      AFTER is_fixed_asset;

-- Zpětné narovnání: co je dnes na řádku označené jako dlouhodobý majetek, je fixed_asset.
-- (Na ostrých datech 0 řádků — příznak je mrtvý u všech 446 položek — ale u jiných tenantů
-- být může a dva zdroje pravdy by se rozešly hned první migrací.)
UPDATE purchase_invoice_items SET expense_kind = 'fixed_asset' WHERE is_fixed_asset = 1;

-- Kontace pro drobný majetek. `invoice.material.received` (501/321) je nasazená už od 1006,
-- jen ji nikdy nikdo nevolal — `buildFromPurchaseInvoice` neměl jak zjistit, že jde o materiál.
-- Drobný majetek dostává vlastní klíč (a ne sdílený s materiálem), aby si ho tenant mohl
-- přesměrovat na vlastní analytiku, aniž tím hne PHM.
-- Idempotence: UNIQUE (supplier_id, rule_key, priority) s supplier_id NULL neochrání
-- (NULL != NULL), proto explicitní guard — týž vzor jako seed v 1006.
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'invoice.small_asset.received', 'Přijatá faktura — drobný majetek (501; DPH odpočet na 343)', '501', '321', 0, 1
  FROM DUAL
 WHERE NOT EXISTS (
   SELECT 1 FROM posting_rules pr WHERE pr.rule_key = 'invoice.small_asset.received' AND pr.supplier_id IS NULL
 );
