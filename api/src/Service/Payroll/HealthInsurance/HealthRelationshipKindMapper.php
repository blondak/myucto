<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use UnexpectedValueException;

final class HealthRelationshipKindMapper
{
    /**
     * `small_scale_employment` splývá se `employment` záměrně. Zaměstnání malého
     * rozsahu je pojem § 7 zákona č. 187/2006 Sb., a proto má práh rozhodného
     * příjmu jen v sociálním pojištění (viz SocialRelationshipKindMapper).
     * Zdravotní pojištění tento pojem nezná — § 5 písm. a) zákona č. 48/1997 Sb.
     * váže příjmovou podmínku jen na taxativně vyjmenované výjimky (DPP, DPČ,
     * člen družstva, dobrovolný pracovník pečovatelské služby), takže pracovní
     * poměr je zaměstnáním bez ohledu na výši příjmu. Nízký příjem se řeší
     * doplatkem do minimálního vyměřovacího základu v HealthMinimumResolver,
     * ne ztrátou účasti.
     */
    public function fromDatabaseRelationType(string $relationType): HealthEmploymentKind
    {
        return match ($relationType) {
            'employment', 'small_scale_employment' => HealthEmploymentKind::Employment,
            'dpp' => HealthEmploymentKind::Dpp,
            'dpc' => HealthEmploymentKind::Dpc,
            'partner_dependent', 'statutory_body' => HealthEmploymentKind::CorporateBody,
            default => throw new UnexpectedValueException(
                "Unsupported payroll relation type {$relationType}.",
            ),
        };
    }
}
