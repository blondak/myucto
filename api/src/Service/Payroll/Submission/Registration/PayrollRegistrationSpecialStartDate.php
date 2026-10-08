<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Datum nástupu v podání u vztahů, které se do evidence ČSSZ zavedly až
 * od 1. 1. 2026.
 *
 * EDV 1.4.0.6, ID 10223: u druhu činnosti 10 až 16 a u výkonu trestu
 * (10502 = 2) nesmí být datum nástupu dřívější než 1. 1. 2026. Všeobecné
 * zásady REGZEC (specifický postup č. 3) pro vztahy vzniklé dřív předepisují
 * v A1 povinně fiktivní datum nástupu 1. 1. 2026. Evidence skutečný nástup
 * nechává beze změny; fiktivní datum se dosazuje jen do věty, a to na jediném
 * místě, které `job/@fro` píše.
 */
final class PayrollRegistrationSpecialStartDate
{
    public const FICTIVE = '2026-01-01';

    private const ACTIVITIES = ['10', '11', '12', '13', '14', '15', '16'];

    private const PRISON_DETAIL = '2';

    /** Datum, které půjde do `job/@fro` místo skutečného nástupu. */
    public static function reported(
        ?string $activityCode,
        ?string $relationshipDetailCode,
        string $actualStartOn,
    ): string {
        return self::appliesTo($activityCode, $relationshipDetailCode)
            && $actualStartOn !== ''
            && $actualStartOn < self::FICTIVE
            ? self::FICTIVE
            : $actualStartOn;
    }

    public static function appliesTo(
        ?string $activityCode,
        ?string $relationshipDetailCode,
    ): bool {
        return ($activityCode !== null && in_array($activityCode, self::ACTIVITIES, true))
            || $relationshipDetailCode === self::PRISON_DETAIL;
    }
}
