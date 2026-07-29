-- MyÚčto.cz — pravidla klasifikace druhu výdaje (§DM, evidence drobného majetku).
--
-- PROČ: `expense_kind` na řádku přijaté faktury (1092) je jen sloupec — někdo ho musí
-- vyplnit. Ručně u 446 položek ročně to nikdo dělat nebude, takže by zůstal NULL a vše
-- by dál padalo na 518 (viz PROČ v 1092). Vestavěná klíčová slova v ExpenseKindClassifier
-- pokrývají obecné případy, ale NE firemní specifika: „AXIGON" je u jednoho tenanta služba
-- a u jiného zboží. Per-tenant pravidlo je jediné místo, kde tohle může žít.
--
-- VZOR = `bank_posting_rules` (1020 + 1056), ZÁMĚRNĚ. Uživatel už zná jejich chování
-- (nullable kritéria s AND, priority ASC first-match-wins, hit_count, is_active) a druhý,
-- jinak se chovající mechanismus pravidel v jedné aplikaci je past. Co se liší:
--   • žádný `mode` suggest/auto — klasifikace SAMA nic neúčtuje, jen předvyplní řádek;
--     rozhodnutí „účtovat automaticky" zůstává na AutoPostingPolicyService.
--   • žádný `rejected_streak` — pravidlo se neodmítá per doklad, uživatel ho prostě
--     přepíše v editoru položky.
--
-- KRITÉRIA JSOU AND PŘES VYPLNĚNÉ, stejně jako u banky. Prázdné pravidlo by chytalo
-- všechno a přeúčtovalo celou firmu → CHECK ho nepustí (týž důvod jako chk_bpr_criteria
-- v 1020). POZOR na past: `amount_min`/`amount_max` do CHECKu ZÁMĚRNĚ NEPATŘÍ. Cenové
-- rozpětí je jen ZÚŽENÍ; ExpenseKindClassifier podle něj nematchuje (matchuje dodavatel
-- a text) a pravidlo „cokoliv od 1 000 do 5 000 = drobný majetek" je přesně ten
-- nekontrolovaný záběr, kvůli kterému CHECK existuje. Rozpětí aplikuje až
-- ExpenseClassificationService, který zná cenu ZA KUS — filtruje jím pravidla PŘED
-- předáním do pure klasifikátoru, takže sloupce nejsou mrtvé.
--
-- CENA ZA KUS, NE ZA ŘÁDEK: 2 ks po 50 000 je pořád drobný majetek (§26/2 ZDP mluví
-- o vstupní ceně jedné věci). Rozpětí se proto porovnává s `unit_price_without_vat`,
-- ne s `total_without_vat` — shodně s prahem 80 000 v ExpenseKindClassifier.
--
-- PRIORITA ASC, FIRST-MATCH-WINS: konkrétní pravidlo („Alza + notebook") musí přebít
-- obecné („Alza"). Bez explicitní priority by o výsledku rozhodovalo pořadí v tabulce.
--
-- vendor_client_id VS vendor_name_contains: obojí, a je to úmysl. FK na `clients` je
-- přesná (Alza je Alza i po přejmenování), textový fragment chytí i dodavatele, kterého
-- v číselníku ještě nemáme — u AI importu je řádek dřív než klient. ON DELETE CASCADE:
-- pravidlo vázané na smazaného klienta je bezcenné, ne osiřelé.
--
-- ŽÁDNÝ GLOBÁLNÍ SEED (supplier_id NOT NULL, bez NULL varianty jako u posting_rules):
-- klasifikace je věcné rozhodnutí účetní jednotky. Obecné případy pokrývají vestavěná
-- klíčová slova v kódu; co je nad rámec, si tenant zavede sám a nese za to odpovědnost.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS expense_classification_rules (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,           -- vždy per-tenant, žádný globální seed
  name                 VARCHAR(120) NOT NULL,           -- „Alza — notebooky", zobrazuje se jako důvod
  vendor_client_id     BIGINT UNSIGNED NULL,            -- přesná shoda dodavatele z číselníku
  vendor_name_contains VARCHAR(120) NULL,               -- fragment jména (dodavatel ještě není v clients)
  description_contains VARCHAR(120) NULL,               -- fragment popisu řádku
  amount_min           DECIMAL(14,2) NULL,              -- cena ZA KUS bez DPH; NULL = bez omezení
  amount_max           DECIMAL(14,2) NULL,
  expense_kind         ENUM('service','material','small_asset','fixed_asset') NOT NULL,
  priority             SMALLINT UNSIGNED NOT NULL DEFAULT 100
                       COMMENT 'first-match-wins, ASC; konkrétní pravidlo nižší číslo než obecné',
  is_active            TINYINT(1) NOT NULL DEFAULT 1,
  hit_count            INT UNSIGNED NOT NULL DEFAULT 0,
  last_hit_at          DATETIME NULL,
  created_by           BIGINT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_ecr_supplier (supplier_id, is_active, priority),
  KEY idx_ecr_vendor (vendor_client_id),
  CONSTRAINT fk_ecr_supplier FOREIGN KEY (supplier_id)      REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_ecr_vendor   FOREIGN KEY (vendor_client_id) REFERENCES clients(id)  ON DELETE CASCADE,
  CONSTRAINT fk_ecr_user     FOREIGN KEY (created_by)       REFERENCES users(id)    ON DELETE SET NULL,
  -- Aspoň jedno MATCHOVACÍ kritérium (viz komentář výš — cenové rozpětí sem nepatří,
  -- protože samo o sobě nematchuje, jen zužuje).
  CONSTRAINT chk_ecr_criteria CHECK (
    vendor_client_id IS NOT NULL
    OR vendor_name_contains IS NOT NULL
    OR description_contains IS NOT NULL
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
