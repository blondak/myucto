-- MyÚčto.cz — dorovnání historie plátcovství DPH po review (EPIC VH, follow-up).
--
-- PROČ (a): firmy, které mezi migracemi 1120 a 1180 změnily plátcovství
-- checkboxem, mají v historii JEN přechodový řádek bez baseline — migrace 1180
-- doplňovala baseline pouze firmám bez jakéhokoli řádku. Pro data před nejstarším
-- řádkem pak čtení „plátce k datu" padá na fallback živé cache, která je po
-- přechodu z definice OPAČNÁ než stav před ním. Přechodový řádek z checkbox éry
-- vznikal výhradně při skutečné změně, takže stav před nejstarším řádkem je
-- prokazatelně jeho negace — tu doplňujeme jako baseline 1900-01-01.
--
-- PROČ (b): backfill is_identified v migraci 1181 cílil na NEJNOVĚJŠÍ řádek
-- firmy, který ale může mít budoucí účinnost — dnešní stav identifikované osoby
-- patří na řádek platný DNES. Oprava přepisuje is_identified z živého supplieru
-- na řádek s max(effective_from <= dnes); jen u neplátcovských řádků (plátce
-- nemůže být identifikovaná osoba).
--
-- Idempotence: (a) INSERT IGNORE (UNIQUE supplier_id + effective_from);
-- (b) UPDATE mění jen řádky, kde se hodnota liší. Vzor 1180/1181.

SET NAMES utf8mb4;

INSERT IGNORE INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified)
SELECT h.supplier_id, '1900-01-01', 1 - h.is_vat_payer, 0
  FROM supplier_vat_status_history h
  JOIN (
      SELECT supplier_id, MIN(effective_from) AS min_from
        FROM supplier_vat_status_history
       GROUP BY supplier_id
  ) m ON m.supplier_id = h.supplier_id AND m.min_from = h.effective_from
 WHERE h.effective_from > '1900-01-01';

UPDATE supplier_vat_status_history h
  JOIN (
      SELECT supplier_id, MAX(effective_from) AS max_from
        FROM supplier_vat_status_history
       WHERE effective_from <= CURDATE()
       GROUP BY supplier_id
  ) cur ON cur.supplier_id = h.supplier_id AND cur.max_from = h.effective_from
  JOIN supplier s ON s.id = h.supplier_id
   SET h.is_identified = s.is_identified
 WHERE h.is_vat_payer = 0 AND h.is_identified <> s.is_identified;
