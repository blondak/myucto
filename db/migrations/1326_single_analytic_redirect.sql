-- ==========================================================================
-- 1326 — přepínač „syntetika s jedinou analytikou → účtuj na analytiku"
-- ==========================================================================
-- Jakmile syntetika dostane potomka, nesmí se na ni dál účtovat (součet analytik
-- by neseděl na syntetiku). Kontace se přesměrovat dají, ale řadu účtů volí engine
-- NATVRDO podle druhu operace — 511 u servisu vozidla, 563/663 u kurzových rozdílů,
-- 648/548 u haléřového dorovnání, 261 u převodu mezi vlastními účty. Honit každý
-- literál zvlášť je nekonečná práce, a přitom když má syntetika právě JEDNU aktivní
-- analytiku, je odpověď jednoznačná.
--
-- Pravidlo proto řeší {@see MyInvoice\Service\Accounting\PostingService::singleAnalyticMap()}
-- centrálně při překladu kódu na account_id. Tenhle sloupec je jeho kill switch.
--
-- ⚠️ DEFAULT 1, a přesto to NENÍ změna pro stávající tenanty bez analytik: pravidlo
--    se aktivuje jen tam, kde pod syntetikou opravdu právě jedna aktivní analytika
--    je. Kdo analytiky nemá, má jich nula → nepřesměruje se nic.
--
-- ⚠️ Přesměr se ZÁMĚRNĚ nepoužije na 221/211/343/345 (analytiku vybírá kontext
--    dokladu) ani na analytiky, které nejsou v tečkovaném tvaru — šablona osnovy
--    veze pod 311 účet `311D` a pod 461 účet `461K`, což jsou úzce účelové
--    podmnožiny, ne náhrady syntetiky. Podrobné zdůvodnění je u té metody.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS (nativní MariaDB, bez PREPARE triků).

SET NAMES utf8mb4;

-- Kdyby byla tabulka na některé instalaci system-versioned (jako journal_entries),
-- ALTER by skončil chybou 4119. Přepínač je no-op tam, kde versioning není.
SET @@system_versioning_alter_history = 1;

ALTER TABLE accounting_supplier_settings
  ADD COLUMN IF NOT EXISTS single_analytic_redirect TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'syntetika s jedinou (tečkovanou) analytikou se účtuje na tu analytiku; 0 = účtovat na syntetiku jako dřív';
