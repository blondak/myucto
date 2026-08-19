-- MyÚčto.cz — Zakázka jako dimenze přijatých dokladů a účetního zápisu (issue #29)
--
-- Kontext: `project_id` (zakázka) byl dosud jen na VYDANÉ straně (`invoices`,
-- `recurring_invoice_templates`, `work_reports`). Přehled zakázky proto uměl jen
-- obrat — ne náklady a ne marži. Klient (cestovní kancelář) k jedné akci váže víc
-- odběratelů i víc dodavatelů a potřebuje průběžně vidět ekonomiku akce.
--
-- Migrace přidává tři sloupce:
--
--   • `purchase_invoices.project_id` — zakázka na přijaté faktuře.
--   • `cash_documents.project_id`    — zakázka na pokladním dokladu.
--   • `journal_entry_lines.project_id` — zakázka jako ANALYTICKÁ DIMENZE řádku
--     účetního zápisu. Razítkuje ji `PostingService` ze zdrojového dokladu, takže
--     výsledovka po zakázkách se počítá z DENÍKU (5xx vs. 6xx) a sedí na účetnictví
--     místo ad-hoc součtu nad hlavičkami dokladů.
--
-- ## Proč NE stávající `cost_center`
--
-- `journal_entry_lines.cost_center` (migrace 1005) i číselník `cost_centers`
-- (1072) tu jsou a řádek by nesly. Použít je by ale znamenalo, že firma smí vést
-- BUĎ střediska, NEBO zakázky — jeden VARCHAR sloupec neunese obojí a mzdy
-- (`payroll_posting_allocations.cost_center`, migrace 1497) do něj už píšou
-- středisko. Středisko = organizační jednotka, zakázka = akce/kontrakt; POHODA,
-- odkud klient přechází, je drží taky odděleně. Samostatný FK sloupec navíc
-- nepotřebuje generovat a hlídat unikátní textové kódy.
--
-- ## Proč ON DELETE SET NULL
--
-- Zakázku drží tenant nezávisle na dokladech; její smazání nesmí shodit doklad
-- ani účetní zápis. Ztráta dimenze je přijatelná (analytika), ztráta dokladu ne.
-- Shodné s `fk_rit_project` (0021) a `fk_pi_advance` (0064).
--
-- Idempotentní: ADD COLUMN/KEY IF NOT EXISTS, FK přes DROP IF EXISTS + ADD
-- (MariaDB neumí ADD CONSTRAINT IF NOT EXISTS) — vzor migrace 0064.

SET NAMES utf8mb4;

-- `journal_entry_lines` (a část dokladových tabulek) je system-versioned; bez tohohle
-- pragmatu skončí ALTER chybou 4119 „Not allowed for system-versioned … Change
-- @@system_versioning_alter_history" (vzor migrace 1122/1125).
SET @@system_versioning_alter_history = 1;

-- ---------------------------------------------------------------------------
-- 1) Přijaté faktury
-- ---------------------------------------------------------------------------
-- projects.id je BIGINT UNSIGNED → FK sloupec MUSÍ být taky UNSIGNED.
ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS project_id BIGINT UNSIGNED NULL
        COMMENT 'Zakázka (issue #29) — analytická dimenze nákladu, nezávislá na dodavateli'
        AFTER expense_category_id;

ALTER TABLE purchase_invoices
    MODIFY COLUMN project_id BIGINT UNSIGNED NULL
        COMMENT 'Zakázka (issue #29) — analytická dimenze nákladu, nezávislá na dodavateli';

ALTER TABLE purchase_invoices
    ADD KEY IF NOT EXISTS idx_pi_project (supplier_id, project_id);

ALTER TABLE purchase_invoices
    DROP FOREIGN KEY IF EXISTS fk_pi_project;
ALTER TABLE purchase_invoices
    ADD CONSTRAINT fk_pi_project
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- 2) Pokladní doklady
-- ---------------------------------------------------------------------------
ALTER TABLE cash_documents
    ADD COLUMN IF NOT EXISTS project_id BIGINT UNSIGNED NULL
        COMMENT 'Zakázka (issue #29) — hotovostní náklad/výnos akce'
        AFTER counter_account_code;

ALTER TABLE cash_documents
    MODIFY COLUMN project_id BIGINT UNSIGNED NULL
        COMMENT 'Zakázka (issue #29) — hotovostní náklad/výnos akce';

ALTER TABLE cash_documents
    ADD KEY IF NOT EXISTS idx_cashdoc_project (supplier_id, project_id);

ALTER TABLE cash_documents
    DROP FOREIGN KEY IF EXISTS fk_cashdoc_project;
ALTER TABLE cash_documents
    ADD CONSTRAINT fk_cashdoc_project
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- 3) Řádky účetního zápisu — dimenze pro výsledovku po zakázkách
-- ---------------------------------------------------------------------------
-- Index je složený (supplier_id, project_id, account_id): report filtruje tenanta,
-- grupuje po zakázce a rozlišuje 5xx/6xx přes účet, takže pokryje celý dotaz.
ALTER TABLE journal_entry_lines
    ADD COLUMN IF NOT EXISTS project_id BIGINT UNSIGNED NULL
        COMMENT 'Zakázka (issue #29) — razítkuje PostingService ze zdrojového dokladu'
        AFTER cost_center;

ALTER TABLE journal_entry_lines
    MODIFY COLUMN project_id BIGINT UNSIGNED NULL
        COMMENT 'Zakázka (issue #29) — razítkuje PostingService ze zdrojového dokladu';

ALTER TABLE journal_entry_lines
    ADD KEY IF NOT EXISTS idx_jel_project (supplier_id, project_id, account_id);

ALTER TABLE journal_entry_lines
    DROP FOREIGN KEY IF EXISTS fk_jel_project;
ALTER TABLE journal_entry_lines
    ADD CONSTRAINT fk_jel_project
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- 4) Backfill dimenze u JIŽ zaúčtovaných vydaných faktur
-- ---------------------------------------------------------------------------
-- `invoices.project_id` existuje odjakživa, takže historické zápisy vydaných
-- faktur dimenzi naplnit umíme a výsledovka po zakázkách nezačne od nuly.
-- Přijatá strana se dorazítkuje až přeúčtováním (dřív zakázku neznala).
UPDATE journal_entry_lines jel
   JOIN journal_entries je ON je.id = jel.entry_id
   JOIN invoices i         ON i.id  = je.source_id AND i.supplier_id = je.supplier_id
    SET jel.project_id = i.project_id
  WHERE je.source_type = 'invoice'
    AND i.project_id IS NOT NULL
    AND jel.project_id IS NULL;
