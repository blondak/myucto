-- 1133 — Zdravotní pojišťovny v remittance_map + doplnění chybějících řádků policy.
--
-- Kontext: odvody pojistného se nikdy nezaúčtovaly automaticky, i když měl tenant
-- preset `full`. Byly na to dvě nezávislé příčiny:
--
-- (A) `remittance_map` uměla identifikovat příjemce jen podle PŘEDČÍSLÍ účtu. To
--     funguje u finančního úřadu (705 DPH, 7704 DPPO, 7720 srážková…), ale zdravotní
--     pojišťovny předčíslí nemají — jejich účet pojistného je celé číslo (VZP
--     1111006311/0710). Řádky pro sociální i zdravotní pojištění proto měly
--     `account_prefix IS NULL`, detektor je vyhodnotil jako neurčité a zastropoval
--     jistotu na 0,70. AutoPostingPolicyService sráží všechno pod 0,90 na `suggest`,
--     takže pojistné NEMOHLO projít automaticky bez ohledu na nastavení.
--     Nově se matchuje i na `account_number` (základ účtu bez předčíslí, viz
--     AccountNumberNormalizer::czechAccountBase) a ten se do jistoty počítá stejně
--     silně jako předčíslí. Bonus: drží i tehdy, když banka do VS pošle DIČ místo
--     čísla pojištěnce — na ostrých datech 8 z 22 plateb VZP mělo VS = DIČ a padalo
--     do fallbacku `bank.remittance.other` s jistotou 0,40.
--
-- (B) `applyPreset()` zapisuje SNÍMEK typů, které existovaly v okamžiku kliknutí.
--     `bank.remittance.social.employer` a `...health.employer` přibyly do kódu až
--     po posledním použití presetu, takže pro ně řádek nikdy nevznikl a `levelFor()`
--     je nechával padat na default `suggest` — přestože tenant má preset `full`.
--     Dokrytí tady je jednorázové; aby se to neopakovalo u dalších nových typů,
--     odvozuje se default nově z uloženého presetu (AutoPostingPolicyService).
--
-- Účty pojišťoven: doplněna JEN VZP (111), ověřená proti reálným platbám. Ostatní
-- pojišťovny se přidají stejným INSERTem, až budou známy jejich účty — chybějící
-- řádek znamená jen nižší jistotu a ruční schválení, kdežto ŠPATNÉ číslo účtu by
-- odvod přiřadilo cizímu příjemci. Proto se sem nic neodhaduje.
SET NAMES utf8mb4;

ALTER TABLE remittance_map
  ADD COLUMN account_number VARCHAR(20) NULL AFTER account_prefix;

-- VZP ČR (kód pojišťovny 111), účet pojistného 1111006311/0710.
-- Dvě varianty podle plátce: OSVČ (fo) → 526/336, zaměstnavatel (po) → 524+331/336.
-- vs_type='other' záměrně: identifikuje účet, VS už rozhodovat nemusí.
INSERT INTO remittance_map
    (bank_code, account_prefix, account_number, vs_type, taxpayer_type,
     operation_type, rule_key, auto_allowed, label_cs)
VALUES
    ('0710', NULL, '1111006311', 'other', 'fo',
     'bank.remittance.health.own', 'insurance.health.paid', 1, 'Zdravotní pojištění OSVČ — VZP'),
    ('0710', NULL, '1111006311', 'other', 'po',
     'bank.remittance.health.employer', 'payroll.health.remittance', 1, 'Zdravotní pojištění za zaměstnance — VZP');

-- ČSSZ: pojistné na sociální zabezpečení chodí na předčíslí 21012, které v mapě
-- dosud chybělo (řádky 10/16 měly account_prefix NULL). Doplněním vyskočí jistota
-- na 0,90 stejně jako u daňových odvodů.
UPDATE remittance_map
   SET account_prefix = '21012'
 WHERE bank_code = '0710'
   AND account_prefix IS NULL
   AND account_number IS NULL
   AND vs_type = 'cssz_vsdp'
   AND operation_type IN ('bank.remittance.social.own', 'bank.remittance.social.employer');

-- (B) Dokrytí typů, které vznikly až po posledním applyPreset(). Úroveň se odvodí
-- z presetu firmy — stejná logika jako applyPreset(), jen pro chybějící řádky.
-- Firmy bez záznamu v accounting_supplier_settings mají default 'suggest' → beze změny.
INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
SELECT s.supplier_id,
       t.operation_type,
       CASE
           WHEN s.automation_level = 'off'      THEN 'off'
           WHEN s.automation_level = 'suggest'  THEN 'suggest'
           WHEN s.automation_level IN ('assisted', 'full') THEN 'auto'
           ELSE 'suggest'
       END,
       NULL
  FROM accounting_supplier_settings s
 CROSS JOIN (
       SELECT 'bank.remittance.social.employer' AS operation_type
       UNION ALL SELECT 'bank.remittance.health.employer'
 ) t
 WHERE NOT EXISTS (
       SELECT 1 FROM auto_posting_policy p
        WHERE p.supplier_id = s.supplier_id AND p.operation_type = t.operation_type
 );
