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

    /**
     * Vzniká u dávky hlášení při ukončení pracovní neschopnosti (HZUPN)?
     *
     * Jen u nemocenského: HZUPN hlásí nástup do zaměstnání po skončení
     * dočasné pracovní neschopnosti nebo karantény. Ošetřovné, otcovská ani
     * mateřská žádnou neschopnost nemají, takže lhůta HZUPN by u nich tvrdila
     * povinnost, která neexistuje. Stejné pravidlo drží obrazovka případů
     * (`HZUPN_KINDS`) i hlídač termínů.
     */
    public function hasEndOfIncapacityReport(): bool
    {
        return $this === self::Nem;
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

    public const DECISION_REQUIRED = 'required';
    public const DECISION_OPTIONAL = 'optional';
    public const DECISION_FORBIDDEN = 'forbidden';

    /**
     * Povinnost čísla rozhodnutí podle logických kontrol NEMPRI25 č. 2 a 3.
     *
     * NEM a OSE/DLO stojí na rozhodnutí lékaře. PPM nese číslo jen tehdy, když
     * nemá `duvodPece` (převzetí dítěte do péče); s důvodem je číslo zakázané.
     * U VPM se číslo nevyplňuje, otcovská ho mít smí, ale nemusí.
     *
     * @return self::DECISION_*
     */
    public function decisionNumberRequirement(bool $hasCareReason = false): string
    {
        return match ($this) {
            self::Nem, self::Ose, self::Dlo => self::DECISION_REQUIRED,
            self::Ppm => $hasCareReason ? self::DECISION_FORBIDDEN : self::DECISION_REQUIRED,
            self::Opp => self::DECISION_OPTIONAL,
            self::Vpm => self::DECISION_FORBIDDEN,
        };
    }

    /**
     * Tvar čísla rozhodnutí podle kontroly č. 2. U NEM `Xnnnnnnn` (číslo
     * z papírové neschopenky) nebo `YYMMDDNNNN`; u ostatních druhů sedmimístné
     * pořadové číslo s písmenem druhu dávky (PPM M, OSE N nebo Z, OPP T, DLO L)
     * a volitelnou předponou ICPE.
     */
    public function decisionNumberPattern(): string
    {
        return match ($this) {
            self::Nem => '/^(?:[A-Z]\d{6,7}|\d{10})$/D',
            self::Ppm => '/^(?:\d{1,10})?\d{7}M$/D',
            self::Ose => '/^(?:\d{1,10})?\d{7}[NZ]$/D',
            self::Opp => '/^(?:\d{1,10})?\d{7}T$/D',
            self::Dlo => '/^(?:\d{1,10})?\d{7}L$/D',
            self::Vpm => '/^$/D',
        };
    }

    /**
     * Platí se výplata dávky na základě platebního spojení i bez akce vznik?
     * U OSE/DLO je spojení povinné při vzniku a bez vzniku zakázané; u ostatních
     * druhů povinné vždy, u NEM jen pro číslo rozhodnutí platné od 1. 1. 2020
     * (elektronické `YYMMDDNNNN`).
     */
    public function paymentConnectionRequirement(
        bool $startsClaim,
        ?string $decisionNumber,
    ): string {
        if ($this->hasActions()) {
            return $startsClaim ? self::DECISION_REQUIRED : self::DECISION_FORBIDDEN;
        }
        if ($this === self::Nem) {
            return $decisionNumber !== null && preg_match('/^\d{10}$/D', $decisionNumber) === 1
                ? self::DECISION_REQUIRED
                : self::DECISION_OPTIONAL;
        }

        return self::DECISION_REQUIRED;
    }
}
