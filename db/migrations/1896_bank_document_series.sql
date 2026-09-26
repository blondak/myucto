-- MyÚčto.cz — dokladová řada bankovního účtu (číslo dokladu bankovního zápisu v deníku).
--
-- Bankovní zápis nesl jako číslo dokladu syrové ID pohybu z banky (bank_ref) nebo
-- technické BANK-<id>. Účetní potřebuje doklad ve tvaru „řada účtu + pořadové číslo
-- měsíčního výpisu" (BCR-08 = běžný CZK účet, výpis za srpen). Číslo skládá
-- MyInvoice\Service\Accounting\Bank\BankDocumentNumber z řady účtu a měsíce data
-- zápisu, takže nezávisí na id výpisu ani na pořadí importu.
--
-- Výchozí řada (shodná s BankDocumentNumber::defaultBase):
--   spořicí BCS, termínovaný vklad BCT, kreditní karta BCK,
--   běžný CZK BCR, běžný v cizí měně BC + první písmeno měny (EUR → BCE).
-- Víc účtů téže výchozí řady ve firmě dostane pořadí podle id: BCR, BCR2, BCR3 …
-- Kandidát obsazený jiným účtem se přeskočí a řadu doplní aplikace při prvním použití.
--
-- Přečíslování existujících zápisů v otevřených obdobích dělá PHP
-- (api/bin/bank-document-series-backfill.php), spouští ho auto-backfill v migrate.php.

SET NAMES utf8mb4;

ALTER TABLE supplier_bank_accounts
  ADD COLUMN IF NOT EXISTS document_series VARCHAR(10) NULL DEFAULT NULL
    COMMENT 'Dokladová řada bankovních zápisů účtu (BCR, BCE …), číslo dokladu = řada-MM'
    AFTER analytic_suffix;

UPDATE supplier_bank_accounts SET document_series = NULL WHERE document_series = '';

UPDATE supplier_bank_accounts a
  JOIN (
    SELECT c.id, c.series
      FROM (
        SELECT r.id, r.supplier_id,
               IF(r.rn = 1, r.base, CONCAT(r.base, r.rn)) AS series
          FROM (
            SELECT b.id, b.supplier_id, b.base,
                   ROW_NUMBER() OVER (PARTITION BY b.supplier_id, b.base ORDER BY b.id) AS rn
              FROM (
                SELECT id, supplier_id,
                       CASE kind
                         WHEN 'savings' THEN 'BCS'
                         WHEN 'term_deposit' THEN 'BCT'
                         WHEN 'credit_card' THEN 'BCK'
                         ELSE CASE
                           WHEN currency IS NULL OR TRIM(currency) = '' OR UPPER(currency) = 'CZK' THEN 'BCR'
                           ELSE CONCAT('BC', LEFT(UPPER(TRIM(currency)), 1))
                         END
                       END AS base
                  FROM supplier_bank_accounts
                 WHERE document_series IS NULL
              ) b
          ) r
      ) c
     WHERE NOT EXISTS (
             SELECT 1 FROM supplier_bank_accounts u
              WHERE u.supplier_id = c.supplier_id AND u.document_series = c.series
           )
  ) m ON m.id = a.id
   SET a.document_series = m.series
 WHERE a.document_series IS NULL;

ALTER TABLE supplier_bank_accounts
  ADD UNIQUE KEY IF NOT EXISTS uq_sba_document_series (supplier_id, document_series);
