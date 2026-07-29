-- MyÚčto.cz — E9 adversariální review: UNIQUE na matched_transaction_id
--
-- Brání tomu, aby souběžný běh párování (dva požadavky /advances/match) přiřadil
-- STEJNOU bankovní transakci dvěma různým předpisům záloh. MariaDB UNIQUE index
-- povoluje libovolný počet NULL hodnot (nezaplacené předpisy), takže nekoliduje
-- s běžným stavem `planned` (matched_transaction_id IS NULL).
--
-- fk_tas_transaction (migrace 1044) používá idx_tas_match jako podpůrný index — proto
-- ho nejdřív zrušíme (DROP FOREIGN KEY IF EXISTS, idempotentní vzor dle 1023/1025/1029),
-- přepneme index na UNIQUE a FK obnovíme.

SET NAMES utf8mb4;

ALTER TABLE tax_advance_schedules DROP FOREIGN KEY IF EXISTS fk_tas_transaction;
DROP INDEX IF EXISTS idx_tas_match ON tax_advance_schedules;
CREATE UNIQUE INDEX IF NOT EXISTS idx_tas_match ON tax_advance_schedules (matched_transaction_id);
ALTER TABLE tax_advance_schedules
    ADD CONSTRAINT fk_tas_transaction FOREIGN KEY (matched_transaction_id) REFERENCES bank_transactions(id) ON DELETE SET NULL;
