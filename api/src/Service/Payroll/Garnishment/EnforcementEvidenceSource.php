<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

/**
 * Proč měsíční exekuční evidence osoby v daném měsíci platí — nebo proč se
 * nevyžadovala.
 *
 * Samotný booleovský příznak (`claim_register_evidence_complete` a spol.)
 * říká jen „ano/ne". Neřekne, jestli JE ne proto, že to nikdo nedoložil,
 * nebo proto, že v tom měsíci nebylo co dokládat — a přesně na tom rozdílu
 * stojí obhajitelnost schválené mzdy. „Rejstřík pohledávek nikdo neprověřil"
 * a „osoba nemá jedinou aktivní pohledávku, takže prověřovat nebylo co" jsou
 * dvě různá tvrzení o tomtéž nule. Za pět let se nebude koho ptát, takže to
 * musí být čitelné ze snímku výpočtu, ne z toho, že se v evidenci nenajde
 * řádek — absenci řádku totiž nelze odlišit od řádku, který někdo smazal.
 *
 * Ukládá se proto vlastním klíčem (`evidence_source` ve snímku výsledku)
 * vedle syrových příznaků ve vstupu, ne jako jejich další hodnota: příznak
 * je vstupní fakt, tohle je metadatum o dokazování. Stejný důvod a stejný
 * tvar jako {@see \MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibilitySource}.
 *
 * Starší revize klíč nemají a dopočítat se NESMÍ — spočítal je kód, který
 * evidenci vyžadoval bezpodmínečně, takže o jejím rozsahu nic netvrdil.
 */
enum EnforcementEvidenceSource: string
{
    /** Příznak je v měsíční evidenci osoby výslovně zapnutý. */
    case Declared = 'declared';

    /**
     * Doložit bylo co a nikdo nic nedoložil. Vzniká issue a výsledek končí
     * v ručním posouzení — tohle je ta větev, která blokovat MUSÍ.
     */
    case Missing = 'missing';

    /**
     * V tomto měsíci nebylo co dokládat: prázdný rejstřík aktivních pohledávek,
     * neuplatněný nárok na vyživovanou osobu či manžela, nebo nezabavitelná
     * částka určená soudem při souběhu plátců. Doklad by nedokládal nic.
     */
    case NotApplicable = 'not_applicable';

    /**
     * Nárok se uplatňuje (zvedá nezabavitelnou částku), ale v tomto měsíci se
     * proti němu nic nesráží — není exekuce ani insolvence. Kvůli mzdovému
     * běhu se tedy neptáme, ale spolehnout se na takovou nezabavitelnou částku
     * nejde: § 148 odst. 2 zákoníku práce z ní odvozuje i strop dobrovolné
     * dohody o srážkách. Proto {@see GarnishmentCalculator::voluntaryDeductionCapacity()}
     * v tomhle stavu vrací nulu — stejně, jako dnes vrací nulu u ručního
     * posouzení. Doložit nárok a dostat kapacitu zpět jde kdykoli.
     */
    case NothingWithheld = 'nothing_withheld';
}
