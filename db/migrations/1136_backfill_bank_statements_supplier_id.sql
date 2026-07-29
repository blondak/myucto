-- MyÚčto.cz — SEC-01: backfill autoritativního tenanta bankovních výpisů
--
-- PROČ: runtime guard (BankStatementOwnershipResolver) nově rozhoduje vlastnictví
-- výpisu podle bank_statements.supplier_id. Fallback podle čísla účtu zůstává jen
-- pro legacy řádky se supplier_id IS NULL a je striktní (kód banky musí sedět na
-- obou stranách, účet musí patřit PRÁVĚ JEDNÉ firmě). Bez backfillu by se část
-- historických výpisů — typicky importy před migrací 1078, kde currencies nemá
-- vyplněný bank_code, nebo kde je účet evidovaný jen IBANem — přestala zobrazovat.
--
-- Tahle migrace je jednorázová „kolaudace" dat: hledá kandidáty ŠIRŠÍ heuristikou
-- (i podle IBANu, i s jednostranně prázdným kódem banky — stejně jako 1078/1079),
-- ale zapisuje jen tehdy, když je vlastník jednoznačný napříč VŠEMI firmami.
-- To je bezpečné: nejednoznačnost je právě ten stav, který útok SEC-01 vytvářel,
-- a jednoznačný výsledek se běhu aplikace nedá zmanipulovat.
--
-- Nejednoznačné (2+ kandidátů) i osamocené (0 kandidátů) řádky ZÁMĚRNĚ zůstávají
-- NULL. Runtime je pak nikomu nezpřístupní — musí se dořešit ručně (přiřadit
-- supplier_id v DB podle papírového výpisu). Radši nedostupný výpis než výpis
-- viditelný cizí firmou.

SET NAMES utf8mb4;

-- 1) Nejsilnější důkaz: transakce výpisu už mají účetní/platební stopu právě
--    jedné firmy (journal_entries / invoice_payments / payment_matches).
--    Stejný zdroj pravdy jako 1078/1079, jen znovu — mezitím přibyla data.
UPDATE bank_statements bs
JOIN (
  SELECT bt.statement_id, MIN(owner.supplier_id) AS supplier_id
    FROM bank_transactions bt
    JOIN (
      SELECT source_id AS bank_transaction_id, supplier_id FROM journal_entries WHERE source_type = 'bank'
      UNION ALL
      SELECT bank_transaction_id, supplier_id FROM invoice_payments WHERE bank_transaction_id IS NOT NULL
      UNION ALL
      SELECT bank_transaction_id, supplier_id FROM payment_matches WHERE bank_transaction_id IS NOT NULL
    ) owner ON owner.bank_transaction_id = bt.id
   GROUP BY bt.statement_id
  HAVING COUNT(DISTINCT owner.supplier_id) = 1
) owned ON owned.statement_id = bs.id
SET bs.supplier_id = owned.supplier_id
WHERE bs.supplier_id IS NULL;

-- 2) Zbytek podle registru vlastních účtů (supplier_bank_accounts, migrace 1053):
--    account_canonical / IBAN / národní zápis, kód banky se porovnává jen když ho
--    má vyplněný obě strany. HAVING COUNT(DISTINCT ...) = 1 = jednoznačný vlastník
--    napříč všemi firmami; při dvou a více kandidátech se nezapisuje nic.
UPDATE bank_statements bs
JOIN (
  SELECT bs2.id AS statement_id, MIN(sba.supplier_id) AS supplier_id
    FROM bank_statements bs2
    JOIN supplier_bank_accounts sba
      ON sba.is_active = 1
     AND (
       REPLACE(UPPER(IFNULL(bs2.account_number, '')), ' ', '') IN (
         REPLACE(UPPER(COALESCE(sba.account_number, '')), ' ', ''),
         REPLACE(UPPER(COALESCE(sba.iban, '')), ' ', '')
       )
       OR TRIM(LEADING '0' FROM REGEXP_REPLACE(SUBSTRING_INDEX(IFNULL(bs2.account_number, ''), '/', 1), '[^0-9]', ''))
        = sba.account_canonical
     )
     AND (COALESCE(bs2.bank_code, '') = '' OR COALESCE(sba.bank_code_norm, '') = '' OR bs2.bank_code = sba.bank_code_norm)
   WHERE bs2.supplier_id IS NULL
     AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs2.account_number, ''), '[^0-9]', '')) <> ''
   GROUP BY bs2.id
  HAVING COUNT(DISTINCT sba.supplier_id) = 1
) owned ON owned.statement_id = bs.id
SET bs.supplier_id = owned.supplier_id
WHERE bs.supplier_id IS NULL;

-- 3) Poslední pokus přes currencies (firmy, které účet nemají v registru 1053).
--    Stejná logika jednoznačnosti; IBAN se porovnává přes domácí část CZ IBANu
--    (CZkk BBBB PPPPPP NNNNNNNNNN → prvních 6 cifer je kontrola + kód banky).
UPDATE bank_statements bs
JOIN (
  SELECT bs3.id AS statement_id, MIN(cur.supplier_id) AS supplier_id
    FROM bank_statements bs3
    JOIN currencies cur
      ON cur.supplier_id IS NOT NULL
     AND (
       TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
         = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs3.account_number, ''), '[^0-9]', ''))
       OR (
         UPPER(REGEXP_REPLACE(IFNULL(cur.iban, ''), '[^A-Za-z0-9]', '')) REGEXP '^CZ[0-9]{22}$'
         AND TRIM(LEADING '0' FROM SUBSTRING(REGEXP_REPLACE(IFNULL(cur.iban, ''), '[^0-9]', ''), 7))
           = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs3.account_number, ''), '[^0-9]', ''))
       )
     )
     AND (COALESCE(TRIM(bs3.bank_code), '') = '' OR COALESCE(TRIM(cur.bank_code), '') = ''
          OR TRIM(cur.bank_code) = TRIM(bs3.bank_code))
   WHERE bs3.supplier_id IS NULL
     AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs3.account_number, ''), '[^0-9]', '')) <> ''
   GROUP BY bs3.id
  HAVING COUNT(DISTINCT cur.supplier_id) = 1
) owned ON owned.statement_id = bs.id
SET bs.supplier_id = owned.supplier_id
WHERE bs.supplier_id IS NULL;

-- Kontrolní dotaz po nasazení (co zbylo k ručnímu dořešení):
--   SELECT id, account_number, bank_code, statement_date, file_name
--     FROM bank_statements WHERE supplier_id IS NULL ORDER BY statement_date;
