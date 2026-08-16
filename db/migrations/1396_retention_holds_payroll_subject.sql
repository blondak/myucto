-- MyÚčto.cz — legal hold se rozšiřuje na osobu, ale ZŮSTÁVÁ JEDEN POJEM.
--
-- PROČ NE VLASTNÍ TABULKA
--
-- Auditní backlog žádal `payroll_retention_holds`. Druhá tabulka by ale rozdělila
-- jeden právní institut na dva a vyrobila by tichou díru: daňová kontrola zadaná
-- na účetní straně (`retention_holds`, migrace 1151) by mzdový výmaz NEZASTAVILA,
-- protože by o ní mzdový modul nevěděl. Přitom mzdové listy jsou v daňové kontrole
-- důkazním prostředkem úplně stejně jako faktury.
--
-- Proto se rozšiřuje existující tabulka. Hold zadaný na firmu platí i na mzdy —
-- automaticky, bez toho, aby si to někdo musel pamatovat.
--
-- ROZSAH HOLDU
--
-- `subject_kind` = 'company'          → celá firma (dosavadní chování)
-- `subject_kind` = 'payroll_employee' → jedna osoba (`subject_id` = payroll_employees.id)
--
-- Osobní rozsah je nutný proto, že exekuce nebo spor se vede PROTI KONKRÉTNÍMU
-- ČLOVĚKU. Kdyby šlo zadržet jen celou firmu, jediná exekuce by zmrazila retenci
-- všem zaměstnancům — a protože retence nic nemaže sama, projevilo by se to jako
-- trvale prázdný návrh výmazu, kterému by nikdo nerozuměl.
--
-- ZPĚTNÁ SLUČITELNOST
--
-- DEFAULT 'company' znamená, že všechny existující řádky si podrží dosavadní
-- význam „platí na celé účetnictví firmy". Účetní brána (`RetentionGuard`) se
-- naopak musí nově ptát výslovně na 'company', aby ji hold na jednu osobu
-- nezačal blokovat mazání faktur — to řeší RetentionHoldRepository.
--
-- FK na `payroll_employees` se ZÁMĚRNĚ nedává. Hold je důkaz o tom, že se něco
-- zadrželo; kdyby ho kaskáda smazala spolu s osobou, zmizel by právě ten záznam,
-- kterým se dokládá, že se osoba nesmazala předčasně. Existenci osoby ověřuje
-- aplikace se supplier scope — stejný vzor jako u 1374.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS + CREATE INDEX IF NOT EXISTS (MariaDB 11.8).

SET NAMES utf8mb4;

ALTER TABLE retention_holds
  ADD COLUMN IF NOT EXISTS subject_kind ENUM('company','payroll_employee') NOT NULL DEFAULT 'company'
      COMMENT 'rozsah zadržení: celá firma nebo jedna mzdová osoba'
      AFTER supplier_id,
  ADD COLUMN IF NOT EXISTS subject_id BIGINT UNSIGNED NULL
      COMMENT 'payroll_employees.id pro subject_kind=payroll_employee; NULL pro company'
      AFTER subject_kind;

-- Mzdové důvody zadržení. Exekuce a insolvence se vedou PROTI OSOBĚ a v účetní
-- čtveřici (kontrola/odvolání/spor/jiné) by skončily jako 'other' — tedy jako
-- důvod, ze kterého při pozdější kontrole nikdo nepozná, co se vlastně zadrželo.
-- MODIFY je ze své podstaty opakovatelný (vzor 1374).
ALTER TABLE retention_holds
  MODIFY reason ENUM('tax_audit','appeal','litigation','enforcement','insolvency','other') NOT NULL;

CREATE INDEX IF NOT EXISTS idx_hold_subject
  ON retention_holds (supplier_id, subject_kind, subject_id, released_on);

-- Rozsah a předmět musí sedět: firemní hold nemá koho jmenovat, osobní hold naopak
-- bez osoby neznamená nic. Bez téhle podmínky by hold s prázdným `subject_id`
-- vypadal jako osobní, blokoval by nikoho a přitom by v přehledu svítil jako aktivní.
ALTER TABLE retention_holds
  ADD CONSTRAINT IF NOT EXISTS chk_hold_subject
    CHECK ((subject_kind = 'company' AND subject_id IS NULL)
        OR (subject_kind = 'payroll_employee' AND subject_id IS NOT NULL));
