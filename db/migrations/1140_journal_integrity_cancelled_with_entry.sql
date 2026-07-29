-- MyÚčto.cz — nový typ nálezu integrity deníku: `cancelled_with_entry`
--
-- Audit účetního jádra 2026-07 (private/checks/NALEZY.md, N-005). Žádná z dosavadních
-- pěti kontrol nedetekuje doklad ve stavu `cancelled`, který má v deníku stále aktivní
-- (posted, nestornovaný) zápis:
--   - orphan_entry řeší jen FYZICKOU neexistenci dokladu (i.id IS NULL), ne jeho stav;
--   - booked_without_entry jde opačným směrem (doklad bez zápisu);
--   - amount_mismatch se na stav dokladu vůbec nedívá.
--
-- Vzniká, když se doklad stornuje cestou, která neprojde přes DocumentJournalSync::onCancel()
-- (import, přímý status transition, migrace dat, backfill). Deník pak drží náklad/výnos
-- a saldokonto dokladu, který už v DPH evidenci neexistuje → trvalá divergence mezi
-- deníkem a evidencí, přesně to, proti čemu DocumentJournalSync vznikl.
--
-- Idempotence: MODIFY COLUMN je opakovatelně spustitelný (cílový ENUM je vždy týž).

SET NAMES utf8mb4;

ALTER TABLE journal_integrity_findings
  MODIFY COLUMN finding_type ENUM(
      'orphan_entry',
      'unbalanced_entry',
      'booked_without_entry',
      'entry_without_booked',
      'amount_mismatch',
      'cancelled_with_entry'
  ) NOT NULL;
