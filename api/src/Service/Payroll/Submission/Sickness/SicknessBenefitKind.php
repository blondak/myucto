<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Druh dávky nemocenského pojištění, tedy `dokument/druhDavky` v NEMPRI25.xsd.
 *
 * Hodnota enumu je přesně hodnota enumerace `StDruhDavky` — velkými písmeny.
 * Překládat ji na hezčí slovo by znamenalo druhé místo, kde se dá splést druh
 * dávky, a rozdíl mezi ošetřovným a dlouhodobým ošetřovným je rozdíl mezi
 * jinou podpůrčí dobou i jiným okamžikem, kdy se hlásí.
 *
 * ## Sestavit jde každý druh
 *
 * `CtNem` a `CtVpm` obsahují jen potvrzení zaměstnavatele. `CtOpp`, `CtPpm`,
 * `CtOse` a `CtDlo` k němu nesou i `zadostODavku` — údaje o dítěti, ošetřované
 * osobě, důvodu péče nebo otcovské. Ty zaměstnavatel NEVYMÝŠLÍ, ale opisuje
 * z žádosti, kterou mu zaměstnanec předal: § 97 odst. 1 zák. č. 187/2006 Sb.
 * mu ukládá žádosti o dávky (s výjimkou nemocenského) přijímat a neprodleně
 * předávat územní správě. Dřívější blokace těchto druhů tak bránila splnit
 * zákonnou povinnost, kterou jiné mzdové programy plní běžně. Úplnost žádosti
 * hlídá {@see SicknessXmlValidator}.
 */
enum SicknessBenefitKind: string
{
    case Nem = 'NEM';
    case Vpm = 'VPM';
    case Opp = 'OPP';
    case Ppm = 'PPM';
    case Ose = 'OSE';
    case Dlo = 'DLO';

    /** Název prvku uvnitř `davka` (CtDruhDavky je `xs:choice`). */
    public function elementName(): string
    {
        return strtolower($this->value);
    }

    /**
     * Nese dávka povinné akce vznik / trvání / ukončení? Jen ošetřovné
     * (`oseVznik` …) a dlouhodobé ošetřovné (`dloVznik` …).
     */
    public function hasActions(): bool
    {
        return $this === self::Ose || $this === self::Dlo;
    }

    /** Nese dávka žádost o dávku (`zadostODavku`)? */
    public function hasApplication(): bool
    {
        return $this !== self::Nem && $this !== self::Vpm;
    }

    /**
     * Má tenhle druh dávky v potvrzení zaměstnavatele pracovní volno bez
     * náhrady příjmu? `CtPotvrzeniZamestnavateleVpm` ani `…Ppm` prvek
     * `volnoBezNahrady` NEMAJÍ, otcovská nese jen základní potvrzení.
     */
    public function hasUnpaidLeaveSection(): bool
    {
        return $this === self::Nem || $this === self::Ose || $this === self::Dlo;
    }

    /**
     * Má druh dávky sekci o studiu? U PPM a OPP ji potvrzení nemá; u NEM,
     * VPM, OSE a DLO je `jeStudentem` povinné.
     */
    public function hasStudentSection(): bool
    {
        return $this !== self::Ppm && $this !== self::Opp;
    }

    /**
     * Musí věta nést číslo rozhodnutí?
     *
     * Nemocenské, ošetřovné i dlouhodobé ošetřovné stojí na rozhodnutí lékaře
     * (eNeschopenka, eOČR, rozhodnutí o potřebě dlouhodobé péče) a ČSSZ podle
     * jeho čísla podání páruje. Otcovská, peněžitá pomoc v mateřství
     * a vyrovnávací příspěvek žádné takové číslo nemají — přijatá podání
     * otcovské ho nenesou. Zahraniční případ číslo z českého systému mít
     * nemusí; rozhoduje o tom {@see SicknessXmlValidator}.
     */
    public function requiresDecisionNumber(): bool
    {
        return $this === self::Nem || $this === self::Ose || $this === self::Dlo;
    }
}
