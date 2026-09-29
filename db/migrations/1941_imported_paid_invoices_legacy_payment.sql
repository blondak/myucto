-- Zaplacené vydané doklady z importu Fakturoidu a iDokladu bez evidované platby (#108)
--
-- Import zapisoval jen status = 'paid' a paid_at, řádek v invoice_payments nezaložil.
-- Spárovaný pohyb z výpisu pak nešel zaúčtovat: rekonciliace neměla platbu, kterou by
-- na pohyb navázala, a návrh skončil v 'already_paid_verify'. Doplní se stejná platba
-- 'legacy' jako v backfillu 0108 a přepočte se paid_total. Zablokované návrhy přepočte
-- StaleSuggestionSweep (already_paid_verify je mezi přechodnými důvody).
--
-- Idempotence: INSERT přes NOT EXISTS, paid_total = přepočet z tabulky.

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source)
SELECT i.supplier_id,
       i.id,
       COALESCE(i.paid_at, i.issue_date),
       i.amount_to_pay,
       COALESCE(cur.code, 'CZK'),
       'legacy'
  FROM invoices i
  LEFT JOIN currencies cur ON cur.id = i.currency_id
 WHERE (i.fakturoid_id IS NOT NULL OR i.idoklad_id IS NOT NULL)
   AND i.status = 'paid'
   AND i.invoice_type IN ('invoice', 'proforma')
   AND i.amount_to_pay > 0
   AND NOT EXISTS (SELECT 1 FROM invoice_payments p WHERE p.invoice_id = i.id);

UPDATE invoices i
  LEFT JOIN (SELECT invoice_id, SUM(amount) AS s FROM invoice_payments GROUP BY invoice_id) p
    ON p.invoice_id = i.id
   SET i.paid_total = COALESCE(p.s, 0)
 WHERE (i.fakturoid_id IS NOT NULL OR i.idoklad_id IS NOT NULL)
   AND i.paid_total <> COALESCE(p.s, 0);
