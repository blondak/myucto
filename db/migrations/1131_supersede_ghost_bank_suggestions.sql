-- 1131 — Úklid „duchů" ve frontě bankovních návrhů.
--
-- Kontext: `matchedOutcome()` v BankPostingService neměl zkratku pro no-op rewrite, takže
-- každý další průchod nad UŽ zaúčtovanou spárovanou transakcí (rematch, reimport výpisu,
-- accept match suggestion) spadl do policy větve a založil nový `pending` návrh. Na ostrých
-- datech takhle viselo 88 návrhů k pohybům, které dávno měly živý zápis v deníku — karta
-- „Zaúčtuj doklady" hlásila 88, tab „Nezaúčtované pohyby" pod prokliknutím 0.
--
-- Příčinu řeší guard v kódu (liveEntryMatching()); tahle migrace uklízí, co už vzniklo.
--
-- ⚠️ ÚMYSLNĚ KONZERVATIVNÍ: superseduje JEN ty návrhy, jejichž kontace se shoduje se
-- zaúčtovaným stavem (týž MD účet, týž D účet, táž částka na haléře). Návrh, který se od
-- živého zápisu LIŠÍ, je legitimní návrh na přepis (typicky po přepárování na jinou fakturu)
-- a musí ve frontě zůstat — proto se tu nemaže paušálně všechno pending nad zaúčtovanou tx.
--
-- Nic se nemaže, jen se mění status na 'superseded' (stejný stav, jaký by nastavil
-- supersedeMatchedForTx()) + rozpoznatelná note pro případnou zpětnou analýzu.
SET NAMES utf8mb4;

UPDATE bank_posting_suggestions s
  JOIN bank_transactions bt ON bt.id = s.bank_transaction_id
  JOIN journal_entries je
    ON je.supplier_id = s.supplier_id
   AND je.source_type = 'bank'
   AND je.source_id = bt.id
   AND je.reversed_by IS NULL
   SET s.status = 'superseded',
       s.note = 'already_posted_cleanup'
 WHERE s.status = 'pending'
   -- MD noha zápisu odpovídá navrženému MD účtu
   AND EXISTS (
       SELECT 1 FROM journal_entry_lines jel
         JOIN chart_of_accounts c ON c.id = jel.account_id
        WHERE jel.entry_id = je.id AND jel.supplier_id = s.supplier_id
          AND jel.side = 'debit'
          AND c.account_code = s.debit_account_code
          AND ROUND(jel.amount * 100) = ROUND(s.amount * 100)
   )
   -- D noha zápisu odpovídá navrženému D účtu
   AND EXISTS (
       SELECT 1 FROM journal_entry_lines jel
         JOIN chart_of_accounts c ON c.id = jel.account_id
        WHERE jel.entry_id = je.id AND jel.supplier_id = s.supplier_id
          AND jel.side = 'credit'
          AND c.account_code = s.credit_account_code
          AND ROUND(jel.amount * 100) = ROUND(s.amount * 100)
   );
