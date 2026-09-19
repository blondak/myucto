-- MyÚčto.cz — denní bankovní výpisy z PDF a jejich načítání z IMAP schránky
--
-- PROČ
--
-- KB (a stejně tak ČSOB, RB a další) posílá klientům „VÝPIS DENNÍ PŘI POHYBU" —
-- za každý den s pohybem jedno PDF, zvlášť za každou měnu účtu. Kdo nemá přímé
-- API, dostává tímhle kanálem jediný autoritativní doklad o pohybech. Dosud
-- končil v příloze e-mailu a musel se nahrávat ručně; a i po nahrání by v přehledu
-- výpisů vznikla hromada jednodenních položek místo jednoho výpisu za měsíc.
--
-- CO SE ZAVÁDÍ
--
-- 1. `bank_statements.period_kind` — pokrývá výpis období (dosavadní chování,
--    výchozí `period`), nebo jediný den (`day`)? Denní výpisy se v seznamu
--    neukazují samostatně: stejným mechanismem jako u strojového feedu (tabulky
--    `bank_api_months` / `bank_api_evidence_months`) se skládají do měsíčního
--    výpisu účtu a zůstávají jako jeho doklad („zdrojové výpisy měsíce").
--    Existující řádky zůstávají `period` — chování dosavadních importů se nemění.
--
-- 2. `bank_email_imap_settings.ingest_pdf_statements` — opt-in per IMAP účet
--    vedle `ingest_pdf_invoices`. Zapnutím se u každé zprávy projdou PDF přílohy
--    a ty, které rozpozná některý bankovní parser výpisů JAKO VÝPIS K ÚČTU TÉTO
--    FIRMY, se naimportují stejnou cestou jako ruční nahrání výpisu (včetně
--    párování plateb). Výchozí stav je VYPNUTO.
--
-- 3. Auditní log příloh (`bank_email_attachment_ingests`) dostane stav
--    `imported_statement` / `skipped_not_statement` a vazbu na založený výpis.
--    Bez toho by uživatel neviděl, proč se příloha nenačetla — a výpis, který se
--    jednou posoudil, by se posuzoval znovu při každém skenu.

SET NAMES utf8mb4;

ALTER TABLE bank_statements
  ADD COLUMN IF NOT EXISTS period_kind ENUM('period','day') NOT NULL DEFAULT 'period'
    COMMENT 'day = výpis za jediný den (skládá se do měsíčního výpisu účtu), period = výpis za období'
    AFTER source_ref;

ALTER TABLE bank_email_imap_settings
  ADD COLUMN IF NOT EXISTS ingest_pdf_statements TINYINT(1) NOT NULL DEFAULT 0 AFTER ingest_pdf_invoices;

ALTER TABLE bank_email_attachment_ingests
  MODIFY COLUMN status ENUM(
    'imported','skipped_duplicate','skipped_not_invoice','rejected','failed',
    'imported_statement','skipped_not_statement'
  ) NOT NULL;

ALTER TABLE bank_email_attachment_ingests
  ADD COLUMN IF NOT EXISTS bank_statement_id BIGINT UNSIGNED NULL AFTER submission_id;

-- Idempotence: cizí klíč nejde založit přes IF NOT EXISTS, tak ho napřed zahodíme.
ALTER TABLE bank_email_attachment_ingests
  DROP FOREIGN KEY IF EXISTS fk_beai_statement;
ALTER TABLE bank_email_attachment_ingests
  ADD CONSTRAINT fk_beai_statement FOREIGN KEY (bank_statement_id)
      REFERENCES bank_statements(id) ON DELETE SET NULL;
