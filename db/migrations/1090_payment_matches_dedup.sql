-- MyÚčto.cz — odstranění duplicitních párování (tx, přijatá faktura) v payment_matches.
--
-- PROČ VZNIKLY: rematch (BankStatementAction::rematch) bere set
-- `match_status IN ('unmatched','auto_partial')`. Větev auto_exact ve StatementMatcheru se
-- duplikace nebojí právem — tx po ní má auto_exact a do setu už nespadne. Větev auto_partial
-- ale tx nechává v auto_partial, takže KAŽDÝ další rematch tutéž dvojici (tx, PF) potkal znovu
-- a INSERT do payment_matches vyrobil další řádek. Typicky u operátorů (Vodafone), kteří
-- inkasují zaokrouhleně na koruny proti haléřovému předpisu → trvalý auto_partial.
--
-- CO TO ZPŮSOBILO: BankPostingService::buildOutgoingMatched sečte VŠECHNY alokace transakce.
-- Dvě alokace po 1 475,00 na platbu 1 475,00 → ΣMD 2 950 vs 1 475 → rozdíl 1 475 Kč přeteče
-- toleranci dorovnání (1 Kč) → PostingException 'allocation_mismatch' → transakce se NIKDY
-- nezaúčtovala. Nešlo tedy o haléře, ale o dvojitou alokaci.
--
-- Kód je opraven ve StatementMatcher (partial větev už existující řádek UPDATEuje místo
-- INSERTu), tahle migrace uklízí data, která stará verze stihla vyrobit.
--
-- PRAVIDLO VÝBĚRU: ponecháváme řádek s NEJNIŽŠÍM id (první, původní párování) — s výjimkou
-- dvojic, kde je i ruční párování: to má přednost před auto, protože ho zapsal člověk vědomě
-- (typicky proto, že auto_partial nedokázal doklad uzavřít). Ruční řádek tedy přežívá i když
-- má vyšší id.
--
-- Dotčené (supplier 1, ověřeno před migrací):
--   tx 248 → PF 9   : id 33 (manual) + 65 (manual)  → zůstává 33
--   tx 465 → PF 238 : id 68 (auto)   + 101 (auto)   → zůstává 68
--   tx 511 → PF 251 : id 80 (auto)   + 81 (manual)  → zůstává 81 (ruční má přednost)
--
-- Migrace je idempotentní a datově obecná — nemaže podle konkrétních id, ale podle pravidla,
-- takže je bezpečná i na jiném datasetu (produkce), kde můžou být duplikáty jiné.
--
-- POZOR: unikátní index na (bank_transaction_id, purchase_invoice_id) VĚDOMĚ NEPŘIDÁVÁME.
-- Do payment_matches zapisuje 8 míst (Action vrstva, MatchSuggestionService, sample data);
-- tvrdý constraint by z tichého duplikátu udělal SQLSTATE 23000 / HTTP 500 v ručním párování.
-- Duplikaci řeší guard v místě, kde prokazatelně vznikala.

-- 1) Dvojice, kde existuje ruční párování → smaž všechna auto párování téže dvojice.
DELETE auto_pm
  FROM payment_matches auto_pm
  JOIN payment_matches manual_pm
    ON manual_pm.supplier_id         = auto_pm.supplier_id
   AND manual_pm.bank_transaction_id = auto_pm.bank_transaction_id
   AND manual_pm.purchase_invoice_id = auto_pm.purchase_invoice_id
   AND manual_pm.match_type          = 'manual'
 WHERE auto_pm.match_type          = 'auto'
   AND auto_pm.purchase_invoice_id IS NOT NULL
   AND auto_pm.invoice_id          IS NULL
   AND manual_pm.invoice_id        IS NULL;

-- 2) Zbylé duplicitní dvojice téhož typu → ponech nejnižší id.
DELETE dup
  FROM payment_matches dup
  JOIN (
        SELECT supplier_id, bank_transaction_id, purchase_invoice_id, MIN(id) AS keep_id
          FROM payment_matches
         WHERE purchase_invoice_id IS NOT NULL AND invoice_id IS NULL
         GROUP BY supplier_id, bank_transaction_id, purchase_invoice_id
        HAVING COUNT(*) > 1
       ) keeper
    ON keeper.supplier_id         = dup.supplier_id
   AND keeper.bank_transaction_id = dup.bank_transaction_id
   AND keeper.purchase_invoice_id = dup.purchase_invoice_id
 WHERE dup.purchase_invoice_id IS NOT NULL
   AND dup.invoice_id          IS NULL
   AND dup.id                  <> keeper.keep_id;
