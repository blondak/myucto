-- MyÚčto.cz — karty evidence drobného majetku (§DM krok 3).
--
-- PROČ: §28 odst. 5 ZoÚ ukládá účetní jednotce vést o majetku, o kterém neúčtuje jako
-- o dlouhodobém, evidenci tak, aby byla prokázána jeho existence a umístění; ČÚS 013
-- bod 5.1.1 to opakuje pro drobný hmotný majetek účtovaný rovnou do spotřeby. §26 odst. 2
-- ZDP dělí svět hranicí 80 000 Kč: nad ni DHM (022 + odpisy), pod ni drobný majetek —
-- jednorázový náklad na 501, ALE s evidencí. Ta u nás dosud nebyla nikde: 1092 dalo
-- řádku faktury `expense_kind`, takže náklad už umí sednout na 501, jenže „prokázat
-- existenci a umístění" ze zaúčtovaného řádku nejde. K inventarizaci účetní potřebuje
-- soupis s umístěním a odpovědnou osobou — a ten je právě tahle tabulka.
--
-- ZDROJ NENÍ JEN FAKTURA — ZJIŠTĚNO Z PRAXE, NE Z TEORIE. V hlavní knize bývá na 501.200
-- pokladní doklad za mobilní telefon s protiúčtem 211.100,
-- tedy nákup z POKLADNY, ne z přijaté faktury. Kdyby evidence uměla jen fakturu, tenhle
-- telefon by v soupisu k inventarizaci chyběl — a to je přesně ta chyba, kvůli které
-- evidence existuje. `cash_documents` nemají položky (1019), takže zdrojem je celý
-- doklad, ne řádek; proto cash_document_id vedle purchase_invoice_item_id.
--
-- DVA NULLABLE FK, NE POLYMORFNÍ source_type/source_id. Zvažováno obojí:
--   • Polymorfně by šlo přidat třetí zdroj bez migrace, jenže na source_id NELZE dát FK.
--     Smazaná faktura by nechala kartu ukazovat do prázdna, nebo hůř — na cizí řádek
--     s recyklovaným id. U evidence, kterou podepisuje účetní jednotka, je tichý osiřelý
--     odkaz horší než migrace navíc.
--   • Dva sloupce mají referenční integritu od DB, ON DELETE se chová správně samo a
--     JOIN na zdroj je obyčejný LEFT JOIN místo CASE přes source_type.
-- Zdroje jsou navíc jen DVA a třetí se nerýsuje. OBA NULL je LEGÁLNÍ STAV — karta
-- pořízená ručně (majetek starší než aplikace, dar, vklad) zdrojový doklad v systému
-- nemá a evidenci mít musí stejně.
--
-- PROČ I purchase_invoice_id, KDYŽ UŽ JE TU ODKAZ NA ŘÁDEK: protože odkaz na řádek je
-- NESTABILNÍ. PurchaseInvoiceRepository::replaceItems() při KAŽDÉ editaci faktury smaže
-- všechny položky a vloží je znovu s NOVÝMI id (volá se ze SetItems action). Karta by
-- tak po nevinné opravě překlepu v popisu ztratila vazbu na doklad úplně. Odkaz na
-- HLAVIČKU faktury editaci přežije, takže „ze kterého dokladu ta věc je" platí dál;
-- purchase_invoice_item_id je jen přesnější, ale pomíjivý ukazatel na konkrétní řádek.
--
-- ON DELETE SET NULL, NE CASCADE: karta je evidence VĚCI, ne přívěsek dokladu. Když
-- někdo smaže koncept faktury, kávovar v kuchyňce nezmizí — jen se ztratí odkaz. Proto
-- zároveň `document_ref`: textový SNAPSHOT čísla dokladu z okamžiku vzniku karty. Bez něj
-- by soupis k inventarizaci po smazání zdroje (nebo po editaci, viz výš) ztratil sloupec
-- „doklad" a věc by se nedala dohledat.
--
-- CHECK „JEN JEDEN ZDROJ" TU ZÁMĚRNĚ NENÍ — MariaDB HO NEPUSTÍ. CHECK, který odkazuje na
-- sloupec s FK ON DELETE SET NULL, končí chybou 1901 („Function or expression '…' cannot
-- be used in the CHECK clause"): SET NULL je pro parser výraz, který by CHECK musel
-- přehodnocovat. Ověřeno na MariaDB 11.8.8 — s ON DELETE RESTRICT/CASCADE tentýž CHECK
-- projde. Volba je tedy mezi integritou zdroje a jednou DB pojistkou navíc a vyhrává
-- integrita: RESTRICT by zablokoval editaci faktury (viz replaceItems výš), CASCADE by
-- evidenci mazal. Invariant „nejvýš jeden zdroj" proto vynucuje SmallAssetService, který
-- je jediným zapisovatelem. CHECKy níž na sloupce BEZ FK fungují normálně a zůstávají.
--
-- MNOŽSTVÍ NA KARTĚ, NE KARTA NA KUS: řádek „3× monitor" by při rozpadu na kus znamenal
-- tři karty a při 100 ks explozi evidence. Karta proto nese `quantity` a `price` = cena
-- za celý řádek (Σ price přes karty tak sedí na 501, což je celý smysl sestavy „rozpis
-- 501"). `unit_price` zůstává vedle, protože hranice §26/2 ZDP se posuzuje podle CENY ZA
-- KUS — 2 ks po 50 000 je pořád drobný majetek, ne DHM za 100 000. Dělení hromadné karty
-- při vyřazení jednoho kusu je vědomě odloženo (dnes se vyřadí celá karta).
--
-- ŽÁDNÝ UNIQUE NA purchase_invoice_item_id: z jednoho řádku smí vzniknout víc karet
-- (uživatel si hromadnou kartu rozdělí ručně) a id řádku se stejně mění při každé editaci.
-- Idempotenci generování drží služba přes přirozený klíč (doklad + název + cena), ne DB.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS small_assets (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id              INT UNSIGNED NOT NULL,

  -- ── zdroj pořízení (faktura NEBO pokladna NEBO ruční karta — viz PROČ výš) ──
  purchase_invoice_id      BIGINT UNSIGNED NULL COMMENT 'hlavička přijaté faktury — stabilní odkaz, přežije editaci položek',
  purchase_invoice_item_id BIGINT UNSIGNED NULL COMMENT 'řádek faktury (expense_kind=small_asset); replaceItems ho při editaci vynuluje',
  cash_document_id         BIGINT UNSIGNED NULL COMMENT 'výdajový pokladní doklad — nákup za hotové (VPD)',
  document_ref             VARCHAR(60) NULL
                           COMMENT 'snapshot čísla zdrojového dokladu; přežije smazání i editaci zdroje (soupis k inventarizaci)',

  -- ── identifikace věci (§28/5 ZoÚ — prokázat existenci a umístění) ──
  name                     VARCHAR(255) NOT NULL,
  inventory_number         VARCHAR(40) NULL COMMENT 'inventární číslo, volitelné — malé firmy ho nevedou',
  vendor_client_id         BIGINT UNSIGNED NULL COMMENT 'dodavatel z číselníku',
  vendor_name              VARCHAR(255) NULL COMMENT 'snapshot jména dodavatele (u pokladny bývá jen volný text)',
  acquisition_date         DATE NOT NULL COMMENT 'datum pořízení = datum dokladu',
  put_into_use_date        DATE NULL COMMENT 'datum zařazení do užívání; NULL = pořízeno, zatím nepoužito',
  quantity                 DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit_price               DECIMAL(14,2) NOT NULL DEFAULT 0.00
                           COMMENT 'cena za kus bez DPH — proti ní se poměřuje hranice §26/2 ZDP',
  price                    DECIMAL(14,2) NOT NULL
                           COMMENT 'pořizovací cena za kartu bez DPH; Σ price = rozpis 501 drobný majetek',
  location                 VARCHAR(160) NULL COMMENT '§28/5 ZoÚ — umístění',
  responsible_person       VARCHAR(160) NULL COMMENT 'odpovědná osoba (volný text — nemusí být uživatel systému)',

  -- ── stav a vyřazení ──
  status                   ENUM('in_use','disposed') NOT NULL DEFAULT 'in_use',
  disposed_at              DATE NULL,
  disposal_reason          VARCHAR(255) NULL,

  notes                    TEXT NULL,
  created_by               BIGINT UNSIGNED NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Soupis k datu filtruje přes supplier + stav + datum pořízení.
  KEY idx_sma_supplier_state (supplier_id, status, acquisition_date),
  -- Přírůstky/úbytky za období jedou přes datum vyřazení.
  KEY idx_sma_supplier_disposed (supplier_id, disposed_at),
  KEY idx_sma_invoice (purchase_invoice_id),
  KEY idx_sma_item (purchase_invoice_item_id),
  KEY idx_sma_cash (cash_document_id),
  KEY idx_sma_vendor (vendor_client_id),

  CONSTRAINT fk_sma_supplier FOREIGN KEY (supplier_id)              REFERENCES supplier(id)               ON DELETE CASCADE,
  CONSTRAINT fk_sma_invoice  FOREIGN KEY (purchase_invoice_id)      REFERENCES purchase_invoices(id)      ON DELETE SET NULL,
  CONSTRAINT fk_sma_item     FOREIGN KEY (purchase_invoice_item_id) REFERENCES purchase_invoice_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_sma_cash     FOREIGN KEY (cash_document_id)         REFERENCES cash_documents(id)         ON DELETE SET NULL,
  CONSTRAINT fk_sma_vendor   FOREIGN KEY (vendor_client_id)         REFERENCES clients(id)                ON DELETE SET NULL,
  CONSTRAINT fk_sma_user     FOREIGN KEY (created_by)               REFERENCES users(id)                  ON DELETE SET NULL,

  -- Vyřazená karta bez data vyřazení nejde vykázat v úbytcích za období a v soupisu
  -- k datu by strašila jako „vyřazeno kdysi". Opačně: datum vyřazení na kartě v užívání
  -- je rozpor sám o sobě.
  CONSTRAINT chk_sma_disposal CHECK (
    (status = 'disposed' AND disposed_at IS NOT NULL)
    OR (status = 'in_use' AND disposed_at IS NULL)
  ),
  -- Vyřadit dřív, než se to koupilo, nejde.
  CONSTRAINT chk_sma_disposal_after CHECK (disposed_at IS NULL OR disposed_at >= acquisition_date),
  CONSTRAINT chk_sma_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
