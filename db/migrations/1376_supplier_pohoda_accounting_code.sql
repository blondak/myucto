-- 1372: předkontace pro Pohoda XML export (supplier.pohoda_accounting_code)
--
-- PROČ: export do Pohody neposílal `<inv:accounting>` vůbec — ani v hlavičce, ani na
-- položkách. Podle invoice.xsd platí, že „pokud není uveden typ předkontace, je nastavena
-- předkontace dle uživatelského nastavení programu Pohoda", takže se KAŽDÝ doklad
-- naimportoval s defaultem cílové instalace: pronájem i služby naskočily jako prodej
-- zboží a účetní přepisovala zaúčtování ručně u každé faktury. Předkontace přitom mezi
-- per-dodavatel Pohoda kódy jako jediná chyběla, takže ji nešlo ani obejít.
--
-- Sloupec drží stejný tvar jako sousední `pohoda_*_code` (VARCHAR(20) NULL, prázdno =
-- element se neposílá a Pohoda si dosadí svůj default — původní chování zůstává
-- výchozím). Zkratka předkontace je číselníková hodnota konkrétní instalace Pohody,
-- proto ji nevalidujeme proti žádnému seznamu.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS (vzor 1179).

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS pohoda_accounting_code VARCHAR(20) NULL
      COMMENT 'Předkontace (zkratka z číselníku Pohody) → <inv:accounting>'
      AFTER pohoda_contract_code;
