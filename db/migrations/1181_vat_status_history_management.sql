-- MyÚčto.cz — správa historie plátcovství DPH (EPIC VH-01).
--
-- PROČ: supplier_vat_status_history (migrace 1120) dosud nesla jen dvojici
-- {effective_from, is_vat_payer} zapisovanou vedlejším efektem checkboxu
-- v Nastavení. Plnohodnotná správa historie potřebuje navíc:
--   - is_identified — identifikovaná osoba (§ 6g–6l ZDPH) je třetí stav vedle
--     plátce/neplátce a mění se v čase stejně jako plátcovství (registrace §6h,
--     zrušení §107a). Bez sloupce v historii by "stav k datu" pro IO neexistoval
--     a živý supplier.is_identified by platil pro celou minulost.
--   - note — lidská poznámka ke změně (č.j. registrace, důvod zrušení), ať je
--     z tabulky v UI poznat, proč ke změně došlo.
--
-- Backfill: nejnovějšímu řádku každé firmy se doplní is_identified z živého
-- supplier.is_identified — živý flag je derivovaná cache stavu "dnes" a dnešek
-- popisuje právě poslední řádek historie. Starší řádky zůstávají 0: zpětně
-- dopočítat IO stav nelze a IO je vzácný stav, default neplátce-bez-IO je
-- konzervativní.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS (vzor 1179); backfill UPDATE mění jen
-- řádky, kde se hodnota liší, takže opakované spuštění nic nepřepíše.

SET NAMES utf8mb4;

ALTER TABLE supplier_vat_status_history
  ADD COLUMN IF NOT EXISTS is_identified TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'identifikovaná osoba (§ 6g–6l ZDPH) od effective_from; vylučuje se s is_vat_payer = 1'
      AFTER is_vat_payer,
  ADD COLUMN IF NOT EXISTS note VARCHAR(255) NULL
      COMMENT 'poznámka ke změně (č.j. registrace, důvod zrušení apod.)';

-- Derived table místo přímého poddotazu na tutéž tabulku (chyba 1093);
-- UNIQUE (supplier_id, effective_from) zaručuje, že MAX(effective_from)
-- určuje právě jeden řádek.
UPDATE supplier_vat_status_history h
  JOIN (
      SELECT supplier_id, MAX(effective_from) AS max_from
        FROM supplier_vat_status_history
       GROUP BY supplier_id
  ) latest ON latest.supplier_id = h.supplier_id AND latest.max_from = h.effective_from
  JOIN supplier s ON s.id = h.supplier_id
   SET h.is_identified = s.is_identified
 WHERE h.is_identified <> s.is_identified;
