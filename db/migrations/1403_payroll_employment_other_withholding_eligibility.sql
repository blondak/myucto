-- MyÚčto.cz — § 6 odst. 4 písm. b) ZDP: prohlášení plátce, zda vztah zakládá
-- účast na nemocenském pojištění.
--
-- Srážkovou daní se bez podepsaného prohlášení daní příjem, jehož úhrn za měsíc
-- nedosáhne částky rozhodné pro účast na nemocenském pojištění. U pracovního
-- poměru, zaměstnání malého rozsahu a DPP plyne odpověď ze samotného druhu
-- vztahu, takže se na nic ptát nemusíme. U odměny jednatele nebo člena
-- statutárního orgánu, u DPČ a u společníka konajícího práci pro s. r. o. ale
-- rozhoduje, jestli sjednaná odměna rozhodné částky dosahuje (§ 7 z. č. 187/2006
-- Sb. — zaměstnání malého rozsahu). Z druhu vztahu to poznat nejde a odhadovat
-- to za plátce daně nebudeme; proto to prohlašuje uživatel.
--
-- Vědomě to NENÍ `tax_regime`: ten je override VÝSLEDKU („zdaň to srážkou"),
-- kdežto tady jde o VSTUPNÍ skutečnost, na kterou výpočet teprve aplikuje
-- rozhodnou částku. Uložit ji do `tax_regime` by hranici § 6 odst. 4 ZDP
-- obešlo — srážka by se použila i nad rozhodnou částkou.
--
-- Default `unverified` znamená „plátce se nevyjádřil" a zákonný výpočet skončí
-- ručním posouzením. Je to přesně chování, které měly tyhle vztahy dosud, takže
-- migrace nemění žádný už spočítaný běh.

SET NAMES utf8mb4;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS other_withholding_eligibility
    ENUM('unverified','eligible','ineligible') NOT NULL DEFAULT 'unverified'
    AFTER tax_regime;
