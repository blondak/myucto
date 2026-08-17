<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Z pracovního vztahu a jeho změn odvodí, co se má oznámit zdravotní
 * pojišťovně.
 *
 * Dvě rozhodnutí, na kterých resolver stojí:
 *
 * 1. **Povinnost, která na zaměstnavatele nedopadá, se nezahazuje.** Vrací se
 *    s `reportedByEmployer = false` a bez lhůty. Kdyby se filtrovala pryč,
 *    nikdo by uživateli neuměl říct, PROČ se skutečnost od 1. 1. 2026
 *    nepodává — a rozdíl mezi „nehlásí se" a „zapomnělo se" je přesně to,
 *    kvůli čemu se platí penále.
 * 2. **Vztah bez účasti na pojištění nevyrábí povinnost žádnou.** Účast
 *    rozhoduje {@see HealthParticipationResolver} ve výpočtu; sem vstupuje
 *    jako fakt, ne jako domněnka odvozená z druhu vztahu.
 */
final readonly class HealthNotificationDutyResolver
{
    public function __construct(
        private HealthNotificationDutyCatalog $duties,
        private HealthNotificationDeadlinePolicy $deadlines,
    ) {}

    /** @return list<HealthNotificationDuty> */
    public function resolve(HealthNotificationFacts $facts): array
    {
        if (!$facts->participates) {
            return [];
        }

        $resolved = [];
        foreach ($this->occurrences($facts) as [$kind, $occurredOn, $insurer]) {
            $resolved[] = $this->duty($facts, $kind, $occurredOn, $insurer);
        }
        usort(
            $resolved,
            static fn (
                HealthNotificationDuty $a,
                HealthNotificationDuty $b,
            ): int => [$a->occurredOn, $a->kind->value]
                <=> [$b->occurredOn, $b->kind->value],
        );

        return $resolved;
    }

    /**
     * Změna pojišťovny se oznamuje OBĚMA dotčeným pojišťovnám. Vrací se proto
     * jako dvě povinnosti — sloučit je do jedné by znamenalo, že se jedna
     * z nich neoznámí.
     *
     * @return list<array{0:HealthNotificationDutyKind,1:string,2:?string}>
     */
    private function occurrences(HealthNotificationFacts $facts): array
    {
        $occurrences = [];
        $add = static function (
            HealthNotificationDutyKind $kind,
            ?string $on,
            ?string $insurer = null,
        ) use (&$occurrences): void {
            if ($on !== null && $on !== '') {
                $occurrences[] = [$kind, $on, $insurer];
            }
        };

        $add(HealthNotificationDutyKind::EmploymentStart, $facts->startedOn);
        $add(HealthNotificationDutyKind::EmploymentEnd, $facts->endedOn);
        $add(
            HealthNotificationDutyKind::EmployeeDataChange,
            $facts->dataChangedOn,
        );
        if ($facts->insurerChangedOn !== null
            && $facts->previousInsurerCode !== null
        ) {
            $add(
                HealthNotificationDutyKind::InsurerChange,
                $facts->insurerChangedOn,
                $facts->previousInsurerCode,
            );
            $add(
                HealthNotificationDutyKind::InsurerChange,
                $facts->insurerChangedOn,
                $facts->insurerCode,
            );
        }
        $add(
            HealthNotificationDutyKind::MaternityLeaveStart,
            $facts->maternityLeaveStartedOn,
        );
        $add(
            HealthNotificationDutyKind::ParentalLeaveStart,
            $facts->parentalLeaveStartedOn,
        );
        $add(
            HealthNotificationDutyKind::MaternityOrParentalLeaveEnd,
            $facts->maternityOrParentalLeaveEndedOn,
        );
        $add(
            HealthNotificationDutyKind::StateCategoryOther,
            $facts->otherStateCategoryOccurredOn,
        );

        return $occurrences;
    }

    private function duty(
        HealthNotificationFacts $facts,
        HealthNotificationDutyKind $kind,
        string $occurredOn,
        ?string $insurerOverride,
    ): HealthNotificationDuty {
        $rule = $this->duties->ruleFor($kind, $occurredOn);
        $insurer = $insurerOverride ?? $facts->insurerCode;
        if ($insurer === null || $insurer === '') {
            throw new HealthNotificationException(
                'zp_insurer_code_missing',
                'Zaměstnanec nemá evidovanou zdravotní pojišťovnu, takže '
                . 'oznámení nemá komu odejít. Doplňte ji v kartě zaměstnance.',
            );
        }

        return new HealthNotificationDuty(
            kind: $kind,
            employmentId: $facts->employmentId,
            employeeId: $facts->employeeId,
            insurerCode: $insurer,
            occurredOn: $occurredOn,
            reportedByEmployer: $rule->employerReports,
            rule: $rule,
            deadline: $rule->employerReports
                ? $this->deadlines->forNotification(
                    $kind,
                    $occurredOn,
                    $facts->relationType,
                )
                : null,
        );
    }
}
