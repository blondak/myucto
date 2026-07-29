-- 1166: § 28 ZDP — odpisovatel (kdo smí majetek odpisovat)
--
-- Karta majetku evidovala jen `is_first_owner`, tedy jeden příznak pro zvýšení odpisu
-- v 1. roce. Právní důvod odpisování — proč zrovna tenhle poplatník majetek odpisuje —
-- nikde nebyl, přestože § 28 rozlišuje několik situací a v každé platí jiná pravidla.
-- Matice daní z příjmů to vedla jako CHYBÍ.
--
-- ── Jaké situace § 28 rozlišuje ─────────────────────────────────────────────────────
--   owner                vlastník (§ 28 odst. 1) — výchozí a naprosto převažující případ
--   lessee_improvement   NÁJEMCE odpisující technické zhodnocení na cizím majetku
--                        (§ 28 odst. 3) — jen se souhlasem vlastníka a jen když vlastník
--                        o hodnotu TZ nezvýšil vstupní cenu
--   co_owner             SPOLUVLASTNÍK — odpisuje ze svého spoluvlastnického podílu
--                        (§ 28 odst. 5), ne z celé pořizovací ceny
--   legal_successor      právní nástupce (přeměna, fúze) — POKRAČUJE v odpisování po
--                        předchůdci (§ 30 odst. 10), nezačíná znovu
--
-- ── Proč to není jen kosmetika ──────────────────────────────────────────────────────
-- Zvýšení odpisu v 1. roce podle § 31 odst. 1 písm. b) až d) náleží jen PRVNÍMU
-- odpisovateli. Právní nástupce pokračuje v odpisování po předchůdci, takže prvním
-- odpisovatelem být nemůže — kdyby si zvýšení uplatnil, jde o neoprávněně sníženou daň.
-- Systém to do teď nemohl ohlídat, protože o nástupnictví nevěděl.
--
-- Spoluvlastnický podíl se eviduje proto, že vstupní cenou je jen poměrná část; bez něj
-- není z čeho ověřit, že se neodpisuje celý majetek místo podílu.

SET NAMES utf8mb4;

ALTER TABLE assets
    ADD COLUMN IF NOT EXISTS depreciator_ground
        ENUM('owner','lessee_improvement','co_owner','legal_successor') NOT NULL DEFAULT 'owner'
        COMMENT 'právní důvod odpisování podle § 28 ZDP'
        AFTER is_first_owner,
    ADD COLUMN IF NOT EXISTS co_ownership_share DECIMAL(5,2) NULL
        COMMENT 'spoluvlastnický podíl v % (§ 28 odst. 5) — vstupní cenou je jen tato část',
    ADD COLUMN IF NOT EXISTS depreciator_note VARCHAR(255) NULL
        COMMENT 'doložení: souhlas vlastníka s odpisováním TZ, rozhodnutí o přeměně apod.';
