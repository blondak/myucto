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
     * Písmeno § 6 odst. 4 ZDP, podle kterého se příjem z vztahu posuzuje
     * pro srážku zvláštní sazbou.
     *
     * Dohoda o provedení práce má vlastní písmeno a) s vlastní rozhodnou
     * částkou (skupina `dpp`). Všechno ostatní spadá pod písmeno b) (skupina
     * `other`) bez ohledu na druh vztahu, protože písmeno b) se ptá jen na
     * „úhrnnou výši nedosahující u téhož plátce daně za kalendářní měsíc
     * rozhodné částky“. Neptá se na sjednanou mzdu ani na účast na nemocenském
     * pojištění, takže pracovní poměr, DPČ, jednatel i společník se zařazují
     * stejně, podle skutečného úhrnu v měsíci.
     *
     * Skupina ještě neznamená srážku: tu určuje až úhrn proti rozhodné částce
     * a podepsané prohlášení poplatníka, viz MonthlyEmploymentIncomeTaxCalculator.
     */
    public function withholdingGroup(): string
    {
        return $this === self::Dpp ? 'dpp' : 'other';
    }
}
