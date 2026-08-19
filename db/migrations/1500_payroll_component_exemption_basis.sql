-- MyÚčto.cz — čím je osvobození mzdové složky DOLOŽENÉ.
--
-- Výpočet daně u složky s `tax_treatment = 'exempt'` vyžadoval
-- `IncomeTaxComponent::hasVerifiedTreatmentEvidence()`, jenže pole
-- `treatment_evidence_*` nenastavoval v produkčním kódu NIKDO — jediné místo,
-- kde složka vzniká, je `PayrollRunStatutoryComponentMapper::incomeTax()`
-- a to předávalo jen čtyři argumenty. Každá osvobozená složka proto skončila
-- na `income-component-exemption-evidence-unverified` a osoba v ručním
-- posouzení. Uzavřít měsíc s cestovní náhradou do zákonného limitu nešlo vůbec
-- a celý roční koš § 6 odst. 9 ZDP (migrace 1480) zůstal v mrtvé větvi.
--
-- Doklad se ale nevymýšlí jako nová evidence: § 6 odst. 9 ZDP ve znění pro rok
-- 2026 žádnou obecnou dokladovou podmínku osvobození neukládá. Fulltextová
-- kontrola celého odstavce najde „prokázaných" JEDINKRÁT, a to v písm. o)
-- (náhrada prokázaných výdajů představitelům státní moci); tvary „doloží",
-- „potvrzení", „písemně" tam nejsou vůbec. Důkazní břemeno plyne z § 92
-- daňového řádu, ne ze ZDP. Chybí tedy záznam o tom, ČÍM je osvobození
-- podložené — ne sken papíru.
--
-- Sloupec `exemption_basis` ten záznam zavádí a rozlišuje tři právně různé
-- situace, které se dosud slily do jediné hodnoty `exempt`:
--
--   not_subject_to_tax  § 6 odst. 7 ZDP — plnění NENÍ PŘEDMĚTEM DANĚ, tedy
--                       vůbec ne osvobozený příjem. Sem patří cestovní náhrada
--                       do limitu podle písm. a); podlimitnost drží klasifikovaný
--                       rozpad vyúčtování cesty (CESTOVNI_NAHRADA_LIMIT /
--                       _NADLIMIT), ne dopočet aplikace.
--   statutory_exempt    § 6 odst. 9 ZDP osvobozuje BEZ limitu — písm. a), c),
--                       e), j), k), q), s). Není co dopočítávat a zákon k tomu
--                       doklad nežádá.
--   benefit_basket      § 6 odst. 9 písm. d) / m) ZDP — osvobozeno „v úhrnu do
--                       výše" ročního limitu. Dokladem je ZMRAZENÝ rozpad koše
--                       na mzdovém vstupu (migrace 1480); bez něj se osvobodit
--                       nedá, protože není známé, kolik z plnění se do koše
--                       vešlo.
--
-- Osvobození vázané na limit, který aplikace neumí spočítat (stravování za
-- směnu podle písm. b), přechodné ubytování podle písm. i)), tady ZÁMĚRNĚ
-- hodnotu nemá: pustit ho by znamenalo osvobodit i nadlimitní část. Takové
-- složky zůstávají na `manual_review` jako dosud.
--
-- Fail-closed se nemění: `exempt` bez `exemption_basis` je dál nedoložené
-- tvrzení a mzdový běh na něm stojí.

SET NAMES utf8mb4;

ALTER TABLE payroll_component_definitions
  ADD COLUMN IF NOT EXISTS exemption_basis
    ENUM('not_subject_to_tax','statutory_exempt','benefit_basket') NULL
    AFTER exemption_basket;

-- MariaDB neumí u CHECK `IF NOT EXISTS`, proto se nejdřív zahazuje.
ALTER TABLE payroll_component_definitions
  DROP CONSTRAINT IF EXISTS chk_payroll_component_exemption_basis;
ALTER TABLE payroll_component_definitions
  ADD CONSTRAINT chk_payroll_component_exemption_basis CHECK (
    exemption_basis IS NULL
    OR (tax_treatment = 'exempt'
        AND (exemption_basis <> 'benefit_basket' OR exemption_basket IS NOT NULL))
  );

-- Zpětné doplnění se drží jen toho, co aplikace o složce SAMA VÍ.
--
-- 1. Složka zařazená do zákonného koše a klasifikovaná jako osvobozená má
--    doklad v rozpadu koše.
UPDATE payroll_component_definitions
   SET exemption_basis = 'benefit_basket'
 WHERE exemption_basis IS NULL
   AND tax_treatment = 'exempt'
   AND exemption_basket IS NOT NULL;

-- 2. Cestovní náhrada do zákonného limitu je vlastní číselníková složka
--    aplikace (MZ-08-W07) a její význam je dán kódem, ne uživatelským zadáním.
UPDATE payroll_component_definitions
   SET exemption_basis = 'not_subject_to_tax'
 WHERE exemption_basis IS NULL
   AND tax_treatment = 'exempt'
   AND code = 'CESTOVNI_NAHRADA_LIMIT';

-- Vlastní složky uživatele označené za osvobozené se NEDOPLŇUJÍ. Uhodnout za
-- účetní, o které ustanovení se opírá, by znamenalo tiše osvobodit plnění,
-- které dosud neprošlo — přesně to, čemu brána brání. Takový vstup dál skončí
-- na `tax_component_exemption_evidence_missing` a účetní podklad doplní.
