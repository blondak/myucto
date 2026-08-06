-- MyÚčto.cz — OSS: rozpoznání a zaúčtování ÚHRADY závazku z režimu jednoho správního místa.
--
-- Migrace 1295 přesunula PŘEDPIS daně v režimu OSS z 343 na vlastní analytiku 345.100.
-- Úhrada ale zůstala na půl cesty: TaxRemittanceDetector znal jediný daňový odvod
-- finančnímu úřadu s DPH kontací (`bank.remittance.vat` → 343), takže platba OSS
-- snižovala 343. Výsledek je horší než původní stav — na 343 vznikl přeplatek, který
-- nesedí s přiznáním, a na 345.100 zůstal závazek, který se nikdy nevynuluje.
--
-- Doplňuje se proto vlastní druh odvodu (`bank.remittance.oss`), jeho kontace
-- (345.100 / 221 — zrcadlo `oss.output.vat` z migrace 1295) a řádek v `remittance_map`,
-- podle kterého detektor platbu pozná.
--
-- ── Jak se platba pozná ────────────────────────────────────────────────────────────
-- Daň v režimu EU se platí na účet Finanční správy 34534-177653621/0710, a to v EURECH
-- (přiznání i platba jsou v měně podání). Referenční číslo platby má tvar
-- „CZ/CZ<DIČ>/Qn.RRRR", tedy NENÍ číselný variabilní symbol — proto se řádek identifikuje
-- ÚČTEM (předčíslí i matriková část) a `vs_type` zůstává 'other'. Předčíslí 34534 je
-- vyhrazené OSS, takže záměna s jiným odvodem nehrozí.
--
-- Účty pro režim mimo EU a pro dovozní režim (IOSS) se sem ZÁMĚRNĚ nedoplňují: aplikace
-- podporuje jen režim EU a chybějící řádek znamená ruční schválení, kdežto špatné číslo
-- účtu by odvod přiřadilo cizímu příjemci (totéž pravidlo jako u pojišťoven v 1133).
--
-- Idempotentní: obojí přes NOT EXISTS nad unikátními klíči.

SET NAMES utf8mb4;

-- 1) Kontace úhrady. MD 345.100 (zánik závazku vůči státu spotřeby) / D 221.
--    Účet MUSÍ zůstat shodný s kreditní stranou kontace `oss.output.vat` (migrace 1295) —
--    předpis a úhrada, které se rozejdou, se navzájem nevynulují, což je přesně ta vada,
--    kterou tahle migrace zavírá. Firma s vlastní analytikou proto musí přepsat OBĚ.
INSERT INTO posting_rules
  (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'tax.oss.paid', 'Úhrada daně v režimu OSS (§110 ZDPH)', '345.100', '221', 0, 1
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM posting_rules pr
   WHERE pr.supplier_id IS NULL AND pr.rule_key = 'tax.oss.paid' AND pr.priority = 0
);

-- 2) Řádek mapy odvodů. Shoda na předčíslí I matrikové části dává detektoru plnou
--    jistotu (0,90) bez ohledu na variabilní symbol — stejný vzorec jako u účtů
--    zdravotních pojišťoven z migrace 1133.
INSERT INTO remittance_map
  (bank_code, account_prefix, account_number, vs_type, taxpayer_type,
   operation_type, rule_key, auto_allowed, label_cs)
SELECT '0710', '34534', '177653621', 'other', 'any',
       'bank.remittance.oss', 'tax.oss.paid', 1, 'Daň v režimu OSS (jedno správní místo)'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM remittance_map m
   WHERE m.bank_code = '0710' AND m.vs_type = 'other'
     AND m.taxpayer_type = 'any' AND m.account_prefix = '34534'
);
