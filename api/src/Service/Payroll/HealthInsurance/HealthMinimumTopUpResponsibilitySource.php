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
}
