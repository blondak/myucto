-- ==========================================================================
-- 1525 — k čemu byl token vydaný: obnova hesla, nebo první nastavení
-- ==========================================================================
-- `password_resets` dosud držela obojí ve stejném tvaru a nešlo je od sebe
-- rozeznat: odkaz z „zapomenuté heslo" i onboardingový odkaz z
-- `PasswordSetupLinkIssuer` (H-33) byly tentýž řádek, jen s jinou platností.
--
-- Rozdíl je ale podstatný v okamžiku, kdy se token uplatní. U prvního nastavení
-- účet právě vzniká a zákazník se po zadání hesla má rovnou dostat dovnitř —
-- posílat ho na přihlašovací formulář, aby tam podruhé opsal heslo, které si
-- před vteřinou vymyslel, je zbytečná překážka na první obrazovce produktu.
-- U obnovy hesla to samé neplatí: tam nejde o první dojem, ale o účet, který
-- už existuje a jehož odkaz mohl uniknout. Sezení se proto vydává JEN pro
-- `setup` (viz `ResetPasswordAction`).
--
-- ⚠️ Výchozí hodnota je `reset`, tedy ta PŘÍSNĚJŠÍ. Historické řádky nevíme
-- čím byly, a kdyby se do `setup` spadlo omylem, uniklý odkaz na obnovu hesla
-- by nově dával rovnou živé sezení. Fail-closed: co nevíme, je `reset`.
-- ==========================================================================

ALTER TABLE `password_resets`
    ADD COLUMN `purpose` ENUM('reset','setup') NOT NULL DEFAULT 'reset'
        COMMENT 'reset = obnova hesla, setup = první nastavení (H-33)'
        AFTER `token_hash`;
