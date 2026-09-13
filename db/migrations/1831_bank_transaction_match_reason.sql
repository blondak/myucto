-- 1831: důvod posledního neúspěšného automatického párování bankovního pohybu (#46).
--
-- StatementMatcher ho zapíše u nespárovaného pohybu (kód jako `no_invoice_with_vs`,
-- `amount_mismatch`, …) a při spárování ho smaže. UI z něj ukáže lidský popisek,
-- aby účetní nemusela dohledávat, proč automat platbu nevzal.
--
-- Re-run safe: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE bank_transactions
  ADD COLUMN IF NOT EXISTS match_reason VARCHAR(40) NULL DEFAULT NULL
    COMMENT 'kód důvodu posledního neúspěšného automatického párování (StatementMatcher)';
