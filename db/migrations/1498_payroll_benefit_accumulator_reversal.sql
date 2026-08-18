-- MyÚčto.cz — storno ročního akumulátoru benefitu (§ 6 odst. 9 ZDP).
--
-- `payroll_benefit_accumulators.status` měl od migrace 1210 hodnotu 'reversed',
-- ale nikdo ji nenastavoval: schválený vstup se zrušit nedal (`assertNoMovement`)
-- a jinou cestu ke stornu kód neměl. Koš osvobození tak zůstal navždy vyčerpaný
-- i tehdy, když účetní zjistila, že plnění bylo jiné nebo žádné.
--
-- Storno je stavový přechod jediného řádku, ne přepočet: `amount_minor` ani
-- zmrazený rozpad na `payroll_inputs` (`benefit_basket`, `benefit_exempt_minor`,
-- `benefit_taxable_minor`) se nesahá. Řádek přestane být 'active', takže ho
-- přestanou sčítat všechny tři čtecí cesty (roční koš, roční úhrn složky, přehled
-- čerpání za firmu) — koš se uvolní a historie zůstane čitelná.
--
-- `reversed_entry_id` se ruší. Byl to druhý mrtvý knoflík téhož nálezu: vazba
-- akumulátor → akumulátor, kterou nikdo nikdy nezapsal ani nečetl. Vztah vstup →
-- akumulátor je 1:1 přes `uq_payroll_benefit_input` a opravné plnění je jiný
-- vstup s vlastním řádkem, takže není co na co odkazovat. Místo něj přibývá
-- evidence, která se opravdu plní: kdo, kdy a proč stornoval.
--
-- Idempotence: DROP FOREIGN KEY / COLUMN / CONSTRAINT s IF EXISTS, ADD COLUMN
-- s IF NOT EXISTS. CHECK constraint se v MariaDB nedá přidat s IF NOT EXISTS,
-- proto se nejdřív zahodí.

SET NAMES utf8mb4;

ALTER TABLE payroll_benefit_accumulators
  DROP FOREIGN KEY IF EXISTS fk_payroll_benefit_reversal;

ALTER TABLE payroll_benefit_accumulators
  DROP COLUMN IF EXISTS reversed_entry_id;

ALTER TABLE payroll_benefit_accumulators
  ADD COLUMN IF NOT EXISTS reversed_at DATETIME NULL
    COMMENT 'Kdy byl akumulátor stornován',
  ADD COLUMN IF NOT EXISTS reversed_by INT UNSIGNED NULL
    COMMENT 'Uživatel, který storno provedl',
  ADD COLUMN IF NOT EXISTS reversal_reason VARCHAR(190) NULL
    COMMENT 'Důvod storna (povinný, aby šlo zpětně doložit uvolnění koše)';

ALTER TABLE payroll_benefit_accumulators
  DROP CONSTRAINT IF EXISTS chk_payroll_benefit_reversal;

ALTER TABLE payroll_benefit_accumulators
  ADD CONSTRAINT chk_payroll_benefit_reversal CHECK (
    (status = 'active' AND reversed_at IS NULL AND reversal_reason IS NULL)
    OR (status = 'reversed' AND reversed_at IS NOT NULL AND reversal_reason IS NOT NULL)
  );
