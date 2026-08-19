<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

/**
 * Jak dopadl uplatněný nárok na slevu podle § 7a po posouzení limitů odst. 3.
 *
 * Doložený nárok a uplatněná sleva NEJSOU totéž. § 7a odst. 3 vyjmenovává
 * situace, kdy sleva „nenáleží", ačkoli je zaměstnanec v okruhu podle odst. 1
 * a nárok je doložený — překročený úhrn vyměřovacích základů, překročený
 * základ na hodinu, překročená odpracovaná doba. To není vada evidence
 * (ta končí `manual_review`), ale zákonný výsledek měsíce, a musí být
 * pojmenovaný: sleva, která tiše zmizela, vypadá jako chyba výpočtu.
 */
enum SocialPartTimeDiscountOutcome: string
{
    case Applied = 'applied';
    case AssessmentBaseAboveLimit = 'assessment_base_above_limit';
    case HourlyAssessmentBaseAboveLimit = 'hourly_assessment_base_above_limit';
    case WorkedHoursAboveLimit = 'worked_hours_above_limit';
    case ShorterWorkingTimeOutsideRange = 'shorter_working_time_outside_range';
}
