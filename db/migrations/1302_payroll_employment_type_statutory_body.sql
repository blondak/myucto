-- 1302: § 59 ZOK — smlouva o výkonu funkce jako pracovněprávní vztah na kartě zaměstnance
--
-- Účetní větev (mzdová rekapitulace nad `payroll_employees`) znala jen `hpp`/`dpp`/`dpc`
-- z migrace 1156, takže člena statutárního orgánu nešlo na kartě odlišit od pracovního
-- poměru. Odměna člena statutárního orgánu přitom NENÍ příjem z pracovněprávního vztahu —
-- je to příjem podle § 6 odst. 1 písm. c) ZDP ze smlouvy o výkonu funkce (§ 59 ZOK).
--
-- ── Proč `statutory_body`, a ne vlastní zkratka (např. `svf`) ───────────────────────
-- Novější mzdový modul týž vztah UŽ ZNÁ pod klíčem `statutory_body`
-- (`payroll_employments.relation_type`, {@see MyInvoice\Service\Payroll\SupportMatrix}),
-- kde je vedený jako plně podporovaný. Kdyby účetní větev zavedla vlastní název, měl by
-- jeden právní pojem v jedné databázi dva identifikátory a mapování mezi větvemi
-- (PayrollPersonCreateValidator) by muselo překládat tam i zpět. Shodný klíč to
-- mapování redukuje na identitu.
--
-- ── Co se tím NEMĚNÍ ────────────────────────────────────────────────────────────────
--   * Kontace 522/366 se řídí `taxpayer_type = managing_partner`, ne tímhle sloupcem —
--     účtování jednatele fungovalo i před touhle migrací a nemění se.
--   * Rozhodný příjem pro účast na nemocenském (§ 6 odst. 1 z. 187/2006, u člena
--     statutárního orgánu přes § 5 písm. a) bod 20) platí stejně jako u ostatních,
--     takže se výpočet sociálního pojištění nemění.
--   * Srážková daň (§ 6 odst. 4 ZDP) se týká VÝHRADNĚ dohody o provedení práce. Odměna
--     člena statutárního orgánu se daní vždy zálohou, i když je nízká — větev srážky
--     v PayrollPostingService::withholdingFor() je proto vázaná na rovnost `= 'dpp'`,
--     ne na negaci, aby do ní nová hodnota nemohla spadnout.
--
-- POZOR: MODIFY COLUMN na ENUM je ALGORITHM=COPY (rebuild tabulky, ne INSTANT) — nová
-- hodnota se přidává na KONEC výčtu, ale MariaDB rebuild stejně provede. Na produkci
-- maintenance window / pt-online-schema-change; `payroll_employees` je řádově malá
-- tabulka (jednotky až desítky řádků na tenanta), takže rebuild je krátký.
--
-- Idempotence: MODIFY COLUMN je deklarativní — opakované spuštění nastaví tentýž stav.

SET NAMES utf8mb4;

ALTER TABLE payroll_employees
    MODIFY COLUMN employment_type ENUM('hpp','dpp','dpc','statutory_body') NOT NULL DEFAULT 'hpp'
        COMMENT 'hpp = pracovní poměr, dpp = dohoda o provedení práce (§ 6/4), dpc = dohoda o pracovní činnosti, statutory_body = smlouva o výkonu funkce (§ 59 ZOK); shodný klíč s payroll_employments.relation_type'
        AFTER taxpayer_type;
