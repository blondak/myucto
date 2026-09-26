<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

/**
 * Odkud se vzala odpovědnost za doplatek do minimálního vyměřovacího základu.
 *
 * Hodnota `top_up_responsibility` sama o sobě neřekne, jestli ji někdo PROHLÁSIL,
 * nebo jestli ji systém ODVODIL ze zákona. Přitom je to rozdíl, na kterém stojí
 * obhajitelnost schválené mzdy: „zaměstnanec hradí, protože to plátce takhle
 * doložil" a „zaměstnanec hradí, protože to tak stanoví § 3 odst. 10 zákona
 * č. 592/1992 Sb. a nikdo netvrdil opak" jsou dvě různá tvrzení o tomtéž číslu.
 * Za pět let se ptát nebude koho, takže to musí být čitelné z uloženého snímku,
 * ne z toho, že se v evidenci nenajde řádek — absence řádku se totiž nedá odlišit
 * od řádku, který mezitím někdo smazal nebo zúžil na jiné období.
 *
 * Proto se do snímku ukládá vlastním klíčem (`top_up_responsibility_source`)
 * vedle výsledné hodnoty, a ne jako další případ enumu `HealthMinimumTopUpResponsibility`:
 * plátce doplatku je věcný závěr, který vstupuje do částky, kdežto tohle je
 * metadatum o dokazování. Kdyby byly v jednom enumu, musel by každý `match`
 * nad plátcem řešit i původ a první opomenutá větev by tiše změnila výpočet.
 *
 * Starší revize klíč nemají a dopočítat se NESMÍ — spočítal je kód, který
 * chybějící evidenci odmítal, takže o původu hodnoty nic netvrdil.
 */
enum HealthMinimumTopUpResponsibilitySource: string
{
    /** Plátce doplatku je zapsaný v měsíční evidenci osoby. */
    case Declared = 'declared';

    /**
     * Měsíční evidence pro tento měsíc neexistuje, takže platí zákonný výchozí
     * stav podle § 3 odst. 10 zákona č. 592/1992 Sb. — doplatek hradí zaměstnanec.
     */
    case StatutoryDefault = 'statutory_default';

    /**
     * Měsíční evidence neexistuje a v měsíci je schválená překážka na straně
     * zaměstnavatele s náhradou nižší než průměrný výdělek (§ 207, § 209 ZP).
     * Vyměřovací základ je nižší z důvodu překážek na straně organizace, takže
     * rozdíl doplácí zaměstnavatel (§ 3 odst. 10 věta třetí zákona
     * č. 592/1992 Sb.). Dokladem je schválená nepřítomnost (`absence:{id}`).
     */
    case DerivedEmployerObstacle = 'derived_employer_obstacle';

    /**
     * Totéž, ale v měsíci je vedle překážky zaměstnavatele i neplacená
     * nepřítomnost (neplacené volno, neomluvená absence, náhradní volno,
     * neplacená překážka zaměstnance). Doplatek pak neplyne jen z překážky
     * a kdo ho hradí, musí rozhodnout účetní v měsíční evidenci — výpočet ho
     * neodhaduje.
     */
    case DerivedMixedCauses = 'derived_mixed_causes';
}
