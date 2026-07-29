-- 1156: § 38d ZDP — typ pracovněprávního vztahu a prohlášení k dani
--
-- Mzdový modul znal jen jeden režim: zálohová daň ze závislé činnosti. Dohoda o provedení
-- práce ani srážková daň v něm neexistovaly, takže firma se zaměstnanci na DPP musela
-- mzdy počítat mimo systém — a šlo přitom o výpočet, který se dá odvodit z dat, která
-- systém má (výše odměny a to, zda je podepsané prohlášení).
--
-- ── Co ty dva sloupce rozhodují ─────────────────────────────────────────────────────
-- § 6 odst. 4 ZDP: příjem z DPP do 10 000 Kč měsíčně od JEDNOHO zaměstnavatele, u kterého
-- poplatník NEPODEPSAL prohlášení k dani, tvoří samostatný základ daně zdaněný srážkou
-- 15 %. Od 1. 1. 2024 je tatáž hranice i limitem pro odvody na sociální a zdravotní
-- pojištění z DPP. Rozhodují tedy současně: druh vztahu, výše odměny a prohlášení.
--
-- `tax_credit_taxpayer` (základní sleva na poplatníka) se v evidenci uplatňuje jen tehdy,
-- je-li prohlášení podepsané, ale NENÍ to totéž: prohlášení lze podepsat i bez nároku na
-- slevu. Proto samostatný sloupec — odvozovat jedno z druhého by u DPP rozhodlo o režimu
-- zdanění na základě údaje, který o něm nevypovídá.

SET NAMES utf8mb4;

ALTER TABLE payroll_employees
    ADD COLUMN IF NOT EXISTS employment_type ENUM('hpp','dpp','dpc') NOT NULL DEFAULT 'hpp'
        COMMENT 'hpp = pracovní poměr, dpp = dohoda o provedení práce (§ 6/4), dpc = dohoda o pracovní činnosti'
        AFTER taxpayer_type,
    ADD COLUMN IF NOT EXISTS tax_declaration_signed TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'podepsané prohlášení k dani; bez něj se DPP do limitu daní srážkou (§ 6/4)'
        AFTER employment_type;

-- Dosavadní zaměstnanci jsou pracovní poměr s prohlášením — to je stav, ze kterého
-- modul dosud vycházel, takže výchozí hodnoty nic nemění na už spočtených mzdách.
