<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Je vztah zaměstnáním pro VEŘEJNÉ ZDRAVOTNÍ POJIŠTĚNÍ?
 *
 * Jediné pravidlo pro oznámení zdravotní pojišťovně (HOZ) i pro varování běhu
 * o chybějícím oznámení nástupu. Stojí nad týmž mapováním druhu vztahu jako
 * výpočet ({@see HealthRelationshipKindMapper}) a nad týmiž prahy
 * ({@see HealthParticipationResolver}), ne nad pravidly ČSSZ. Dřív se účast
 * pro HOZ brala z nemocenského pojištění, takže zaměstnání malého rozsahu,
 * jednatel s odměnou pod rozhodným příjmem a DPP nad limitem se pojišťovně
 * nehlásily, ačkoli z nich výpočet pojistné odváděl.
 *
 * § 5 písm. a) zákona č. 48/1997 Sb. váže příjmovou podmínku jen na DPP, DPČ
 * a další vyjmenované výjimky. Pracovní poměr (i zaměstnání malého rozsahu)
 * a člen orgánu s odměnou jsou zaměstnáním bez ohledu na výši příjmu:
 *
 *  - `included` ano, `excluded` a `foreign` ne,
 *  - pracovní poměr a zaměstnání malého rozsahu ano,
 *  - člen orgánu a společník v závislé činnosti, má-li SJEDNANOU odměnu;
 *    bez ní se zaměstnancem pro ZP netvrdí, protože z čeho by plynulo
 *    pojistné, aplikace neví,
 *  - DPČ, když sjednaná měsíční odměna dosahuje prahu účasti DPČ, nebo když
 *    účast doložil schválený mzdový běh,
 *  - DPP JEN podle schváleného mzdového běhu: účast rozhoduje úhrn všech DPP
 *    u zaměstnavatele za kalendářní měsíc, a ten při nástupu znát nejde.
 */
final class PayrollExpectedHealthParticipation
{
    private const DPC_THRESHOLD_KEY = 'participation.dpc.minimum';

    /** Druhy vztahu, u kterých o účasti rozhoduje až skutečný příjem měsíce. */
    private const RUN_DECIDED_KINDS = [
        HealthEmploymentKind::Dpp,
        HealthEmploymentKind::Dpc,
    ];

    /**
     * @param bool $participatedInRun schválený mzdový běh u vztahu doložil
     *        účast na zdravotním pojištění
     */
    public static function expected(
        ?string $participation,
        string $relationType,
        ?int $agreedMonthlyMinor,
        ?int $dpcThresholdMinor,
        bool $participatedInRun = false,
        ?string $activityCode = null,
    ): bool {
        $value = $participation ?? 'automatic';
        if ($value === 'included') {
            return true;
        }
        if ($value === 'excluded' || $value === 'foreign') {
            return false;
        }
        // Druhy činnosti 11 až 14 zaměstnancem pro pojištění nečiní; výpočet
        // je posuzuje stejně ({@see HealthParticipationResolver}).
        if (PayrollEmploymentJmhzActivityFamily::isOutsideStatutoryInsurance($activityCode)) {
            return false;
        }
        $kind = self::kind($relationType);

        return match ($kind) {
            HealthEmploymentKind::Employment => true,
            HealthEmploymentKind::CorporateBody => $agreedMonthlyMinor !== null
                && $agreedMonthlyMinor > 0,
            HealthEmploymentKind::Dpc => $participatedInRun
                || ($agreedMonthlyMinor !== null
                    && $dpcThresholdMinor !== null
                    && $agreedMonthlyMinor >= $dpcThresholdMinor),
            HealthEmploymentKind::Dpp => $participatedInRun,
            default => false,
        };
    }

    /**
     * Rozhoduje u vztahu o účasti skutečný příjem měsíce (DPP, DPČ)? U nich
     * se účast doložená během čte z výsledku výpočtu a přihláška se váže
     * k měsíci, ve kterém účast poprvé vznikla.
     */
    public static function decidedByMonthlyIncome(string $relationType): bool
    {
        return in_array(self::kind($relationType), self::RUN_DECIDED_KINDS, true);
    }

    /** Práh účasti DPČ k datu z pravidel ZDRAVOTNÍHO pojištění; `null`, když pravidla nejsou. */
    public static function dpcThreshold(PayrollRulesetProvider $rulesets, string $onDate): ?int
    {
        try {
            $parameter = $rulesets->forCalculation(PayrollRulesetDomain::HealthInsurance, $onDate)
                ->parameter(self::DPC_THRESHOLD_KEY);
        } catch (\Throwable) {
            return null;
        }

        return $parameter->type === 'money_minor' && is_int($parameter->value) ? $parameter->value : null;
    }

    private static function kind(string $relationType): ?HealthEmploymentKind
    {
        try {
            return (new HealthRelationshipKindMapper())->fromDatabaseRelationType($relationType);
        } catch (\UnexpectedValueException) {
            return null;
        }
    }
}
