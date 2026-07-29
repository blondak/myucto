-- MyÚčto.cz — Epic F1 fix: DB-level idempotence účetního zápisu (bezpečnostní audit)
--
-- PostingService idempotoval jen check-then-act (findBySource → insert) BEZ DB
-- záruky → souběžné dva postDocument téhož dokladu (dvojklik / retry / cron 2×)
-- oba nenašly nic a oba vložily = DVA účetní zápisy pro jeden doklad (dvojí
-- zaúčtování, Σ zdvojená). Tvrdá pojistka: UNIQUE (supplier_id, source_type,
-- source_id). Druhý souběžný INSERT teď selže na duplicate key a aplikace ho
-- převede na idempotentní přepis (viz PostingService::postDocument).
--
-- NULL source_id (manuální zápisy i storno protizápisy) MariaDB v UNIQUE bere
-- jako navzájem různé → jich může být libovolně mnoho (žádoucí; storno má
-- source_id NULL úmyslně). Nahrazuje nevynucující KEY idx_je_supplier_source.
--
-- Idempotence migrace: DROP INDEX IF EXISTS + CREATE UNIQUE INDEX IF NOT EXISTS.
-- (Předpoklad: žádné existující duplicity — jádro je nové, produkce zatím není.)

SET NAMES utf8mb4;

ALTER TABLE journal_entries DROP INDEX IF EXISTS idx_je_supplier_source;
CREATE UNIQUE INDEX IF NOT EXISTS uq_je_supplier_source
  ON journal_entries (supplier_id, source_type, source_id);
