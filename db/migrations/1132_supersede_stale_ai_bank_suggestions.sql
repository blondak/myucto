-- 1132 — Úklid zvětralých AI návrhů nad už zaúčtovanými bankovními pohyby.
--
-- Kontext: job `classify_bank_tx` je jen fotka stavu v okamžiku zařazení do fronty.
-- `AiSuggestionService::suggestBankNow()` při jeho zpracování NEověřoval, jestli pohyb
-- mezitím někdo nezaúčtoval. Na ostrých datech ležely joby ve frontě čtyři dny; než je
-- worker vzal, měly všechny dotčené tx dávno živý zápis v deníku (pravidlo, detektor
-- nebo ruční kontace). LLM je přesto překlasifikovala — 58 `pending` návrhů s confidence
-- 0,18–0,34 nad pohyby, které nikdo účtovat nepotřeboval, plus utracené tokeny.
--
-- Příčinu řeší guardy `already_posted` / `rule_matched` v AiSuggestionService (běží PŘED
-- sanitizací i rezervací denního limitu, takže zvětralý job nestojí ani token); tahle
-- migrace uklízí, co už vzniklo.
--
-- ⚠️ Vymezení proti 1131: tam se superseduje jen návrh SHODNÝ se zaúčtovaným stavem,
-- protože odlišný návrh nad zaúčtovanou tx je legitimní návrh na přepis (rematch na jinou
-- fakturu). Tady je to naopak — AI návrhy se od živého zápisu skoro vždy LIŠÍ, ale nejde
-- o návrh na přepis: vznikly z jobu, který se vůbec neměl dopočítat. Proto se omezuje
-- výhradně na `source IN ('llm','knn')`; návrhy z pravidel, detektorů a učení zůstávají
-- nedotčené, ať se konzervativní chování 1131 nerozbije.
--
-- Nic se nemaže, jen se mění status na 'superseded' + rozpoznatelná note pro zpětnou analýzu.
SET NAMES utf8mb4;

UPDATE bank_posting_suggestions s
  JOIN journal_entries je
    ON je.supplier_id = s.supplier_id
   AND je.source_type = 'bank'
   AND je.source_id = s.bank_transaction_id
   AND je.reversed_by IS NULL
   SET s.status = 'superseded',
       s.note = 'stale_ai_cleanup'
 WHERE s.status IN ('pending', 'needs_input', 'blocked')
   AND s.source IN ('llm', 'knn');
