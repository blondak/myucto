-- MyÚčto.cz: druh srážky „převzatá z podkladů" (import docházky, převod PAMICA).
--
-- Srážka ze sloupce „Srážky" podkladů docházky (v převodu PAMICA složka
-- zadaná částkou, např. S07) se zakládala jako „Jiná srážka" s titulem dohoda
-- o srážkách, a tím vstupovala do JMHZ 10116. Dohoda o srážkách podle
-- občanského zákoníku za ní ale doložená není (typicky jde o srážky za
-- stravování z docházkového systému) a PAMICA ji do 10116 nehlásí. Vlastní
-- druh drží srážku v běhu beze změny a jen ji vyřadí z příznaku 10116.

SET NAMES utf8mb4;

ALTER TABLE payroll_deduction_agreements
    MODIFY COLUMN deduction_kind
        ENUM('advance', 'meal', 'contribution', 'damage', 'other', 'imported') NOT NULL;

ALTER TABLE payroll_deduction_agreement_versions
    MODIFY COLUMN deduction_kind
        ENUM('advance', 'meal', 'contribution', 'damage', 'other', 'imported') NOT NULL;
