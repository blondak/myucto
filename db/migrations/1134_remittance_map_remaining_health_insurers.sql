-- 1134 — Doplnění zbývajících zdravotních pojišťoven do remittance_map.
--
-- Navazuje na 1133, která zavedla sloupec `account_number` a doplnila VZP. Tady
-- přibývá zbytek českých zdravotních pojišťoven, aby odvod pojistného dostal plnou
-- jistotu (0,90) a mohl projít automaticky i u tenantů mimo VZP.
--
-- Samostatná migrace, ne úprava 1133: ta je na ostrých datech už aplikovaná
-- a zapsaná v `migrations`, takže by se změna nikdy nespustila.
--
-- KAŽDÝ účet se vkládá DVAKRÁT — pro `fo` (OSVČ → bank.remittance.health.own) i pro
-- `po` (zaměstnavatel → bank.remittance.health.employer). Je to záměr: obě operace
-- účtují shodně 336/221 a liší se jen předpisem, který úhradu kryje, takže o variantě
-- rozhoduje taxpayer_type plátce, ne číslo účtu. Odpadá tím riziko, že by se účet
-- OSVČ omylem označil jako zaměstnavatelský — na kontaci by to stejně nic nezměnilo.
--
-- ÚČTY PENÁLE A POKUT SE ZÁMĚRNĚ NEVKLÁDAJÍ (RBP 2131409761, ZPMV 2117101031 a další).
-- Penále není pojistné: nepatří na 336, ale mezi ostatní pokuty a penále (545), takže
-- by ho tahle mapa zaúčtovala špatně. Zůstane na ručním posouzení.
--
-- Ověření čísel účtů (všechny u ČNB, kód banky 0710):
--   VoZP  201  2010201091  — kodap.cz i cislouctu.cz se shodly
--   ČPZP  205  2050203761  — kodap.cz i cislouctu.cz se shodly
--   OZP   207  2070101041  — kodap.cz i cislouctu.cz se shodly
--   ZPŠ   209  2092101181  — kodap.cz i cislouctu.cz se shodly
--   ZPMV  211  2110102031 (OSVČ) + 2115106031 (zaměstnavatel) — potvrzeno přímo
--                            na zpmvcr.cz/platci/ucty-pro-prijem-plateb
--   RBP   213  2130203761  — potvrzeno přímo na rbp213.cz; přehledy se rozcházely
--                            (kodap.cz 2130203761 vs. cislouctu.cz 2130502761),
--                            rozhodl oficiální web pojišťovny
--
-- NEDOPLNĚNO ZÁMĚRNĚ:
--   - další účty ČPZP (2050406761, 2050000761, 2050107761) — přehled je uvádí bez
--     popisu, k čemu slouží; nepřiřazený účet raději vynechat než hádat,
--   - ostatní regionální účty VZP — VZP má účet PER KRAJSKOU POBOČKU (1111006311,
--     1111009221, …) a úplný seznam se nepodařilo z webu VZP získat. Platba VZP mimo
--     doplněný kraj se rozpozná přes stávající řádek podle VS (číslo pojištěnce),
--     jen s jistotou 0,70 → skončí jako návrh ke schválení, nikoli špatně zaúčtovaná.
SET NAMES utf8mb4;

INSERT INTO remittance_map
    (bank_code, account_prefix, account_number, vs_type, taxpayer_type,
     operation_type, rule_key, auto_allowed, label_cs)
SELECT * FROM (
    SELECT '0710' AS bank_code, NULL AS account_prefix, v.acc AS account_number,
           'other' AS vs_type, p.tp AS taxpayer_type,
           CASE p.tp WHEN 'fo' THEN 'bank.remittance.health.own'
                     ELSE 'bank.remittance.health.employer' END AS operation_type,
           CASE p.tp WHEN 'fo' THEN 'insurance.health.paid'
                     ELSE 'payroll.health.remittance' END AS rule_key,
           1 AS auto_allowed,
           CONCAT(CASE p.tp WHEN 'fo' THEN 'Zdravotní pojištění OSVČ — '
                            ELSE 'Zdravotní pojištění za zaměstnance — ' END, v.nm) AS label_cs
      FROM (
            SELECT '2010201091' AS acc, 'VoZP' AS nm
            UNION ALL SELECT '2050203761', 'ČPZP'
            UNION ALL SELECT '2070101041', 'OZP'
            UNION ALL SELECT '2092101181', 'ZPŠ'
            UNION ALL SELECT '2110102031', 'ZP MV ČR'
            UNION ALL SELECT '2115106031', 'ZP MV ČR'
            UNION ALL SELECT '2130203761', 'RBP'
      ) v
      CROSS JOIN (SELECT 'fo' AS tp UNION ALL SELECT 'po') p
) src
WHERE NOT EXISTS (
    SELECT 1 FROM remittance_map m
     WHERE m.bank_code = src.bank_code
       AND m.account_number = src.account_number
       AND m.taxpayer_type = src.taxpayer_type
);
