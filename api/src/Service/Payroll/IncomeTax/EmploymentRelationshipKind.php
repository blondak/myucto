<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

enum EmploymentRelationshipKind: string
{
    case Employment = 'employment';
    case SmallScaleEmployment = 'small-scale-employment';
    case Dpp = 'dpp';
    case Dpc = 'dpc';
    case ManagingPartnerDependent = 'managing-partner-dependent';
    case StatutoryBody = 'statutory-body';

    /**
     * Skupina § 6 odst. 4 ZDP, kterou určuje sám druh vztahu.
     *
     * `null` znamená „srážka nepřipadá v úvahu, daní se zálohou" — a to jak
     * u pracovního poměru, tak u vztahů, které se bez prohlášení plátce zařadit
     * nedají (viz {@see requiresOtherWithholdingStatement()}). Tyhle dvě příčiny
     * odlišuje právě ta metoda; sama skupina je v obou případech prázdná.
     */
    public function automaticWithholdingGroup(): ?string
    {
        return match ($this) {
            // Dohoda o provedení práce má vlastní rozhodnou částku podle
            // § 6 odst. 4 písm. a) ZDP, proto vlastní skupinu.
            self::Dpp => 'dpp',
            // Zaměstnání malého rozsahu je z definice (§ 7 z. č. 187/2006 Sb.)
            // vztah, jehož sjednaný příjem rozhodné částky nedosahuje — účast
            // na nemocenském pojištění tedy vzniká až podle skutečného příjmu
            // v měsíci, což je přesně test § 6 odst. 4 písm. b) ZDP.
            self::SmallScaleEmployment => 'other',
            // Pracovní poměr se sjednanou mzdou nad rozhodnou částkou zakládá
            // účast od počátku; nižší sjednaná mzda z něj dělá zaměstnání
            // malého rozsahu, což je v modelu samostatný druh vztahu.
            self::Employment => null,
            self::Dpc,
            self::ManagingPartnerDependent,
            self::StatutoryBody => null,
        };
    }

    /**
     * Vztahy, u kterých musí zařazení podle § 6 odst. 4 písm. b) ZDP prohlásit
     * plátce daně.
     *
     * Odměna jednatele nebo člena statutárního orgánu, DPČ a práce společníka
     * pro vlastní s. r. o. můžou účast na nemocenském pojištění zakládat i
     * nezakládat — rozhoduje sjednaná částka započitatelného příjmu proti
     * rozhodné částce (§ 7 z. č. 187/2006 Sb.). Ta v datech mzdového vztahu
     * spolehlivě není: `monthly_gross_minor` je vstup pro výpočet, ne sjednaná
     * odměna, a u odměny určené valnou hromadou nemusí být sjednaná vůbec.
     * Uhodnout to za plátce daně by znamenalo tiše rozhodnout o jeho ručení,
     * takže se místo toho ptáme (sloupec
     * `payroll_employment_terms.other_withholding_eligibility`, migrace 1403).
     */
    public function requiresOtherWithholdingStatement(): bool
    {
        return match ($this) {
            self::Dpc,
            self::ManagingPartnerDependent,
            self::StatutoryBody => true,
            self::Employment,
            self::SmallScaleEmployment,
            self::Dpp => false,
        };
    }
}
