<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Jedna konkrétní oznamovací povinnost: co, kdy vzniklo, komu se to hlásí,
 * do kdy, z čeho to plyne — a jestli se za ni vůbec podává.
 */
final readonly class HealthNotificationDuty
{
    /** Přestup: odhláška u dosavadní pojišťovny (kód „O"). */
    public const DIRECTION_OUTGOING = 'outgoing';

    /** Přestup: přihláška u nové pojišťovny (kód „P"). */
    public const DIRECTION_INCOMING = 'incoming';

    /**
     * @param string|null $insurerDirection jen u přestupu mezi pojišťovnami —
     *        tatáž skutečnost se hlásí oběma pojišťovnám a každé jiným kódem
     */
    public function __construct(
        public HealthNotificationDutyKind $kind,
        public int $employmentId,
        public int $employeeId,
        public string $insurerCode,
        public string $occurredOn,
        public bool $reportedByEmployer,
        public HealthNotificationDutyRule $rule,
        public ?HealthNotificationDeadlineWindow $deadline,
        public ?string $insurerDirection = null,
    ) {}

    public function subjectReference(): string
    {
        return 'employment:' . $this->employmentId;
    }

    /**
     * Odhláška u dosavadní pojišťovny nese příponu směru. Bez ní měly obě
     * poloviny přestupu stejnou referenci a v evidenci povinností přepsala
     * přihláška odhlášku — jedna pojišťovna se tak nedozvěděla nic. Přihláška
     * u nové pojišťovny referenci bez přípony zachovává, takže povinnosti
     * zaevidované dřív zůstávají spárované.
     */
    public function sourceEventReference(): string
    {
        return sprintf(
            'payroll_health_notification:%d:%s:%s',
            $this->employmentId,
            $this->kind->value,
            $this->occurredOn,
        ) . ($this->insurerDirection === self::DIRECTION_OUTGOING ? ':' . self::DIRECTION_OUTGOING : '');
    }

    /**
     * Den, který se do věty zapíše jako den změny. U odhlášky při přestupu je
     * to poslední den pojištění u dosavadní pojišťovny, tedy den před změnou;
     * od dne změny už pojištěnce kryje nová pojišťovna. Stejně se u ukončení
     * zaměstnání hlásí poslední den trvání, ne den následující.
     */
    public function reportedChangeOn(): string
    {
        if ($this->kind !== HealthNotificationDutyKind::InsurerChange
            || $this->insurerDirection !== self::DIRECTION_OUTGOING
        ) {
            return $this->occurredOn;
        }

        return (new \DateTimeImmutable($this->occurredOn))
            ->modify('-1 day')
            ->format('Y-m-d');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'employment_id' => $this->employmentId,
            'employee_id' => $this->employeeId,
            'insurer_code' => $this->insurerCode,
            'insurer_direction' => $this->insurerDirection,
            'occurred_on' => $this->occurredOn,
            'reported_by_employer' => $this->reportedByEmployer,
            'rule' => $this->rule->toArray(),
            'deadline' => $this->deadline?->toArray(),
        ];
    }
}
