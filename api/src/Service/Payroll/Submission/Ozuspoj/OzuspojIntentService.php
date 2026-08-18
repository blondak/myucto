<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Ozuspoj;

use MyInvoice\Repository\Payroll\PayrollDiscountIntentRepository;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPartTimeDiscountReason;
use Psr\Clock\ClockInterface;

/**
 * Evidence záměrů uplatňovat slevu na pojistném — životní cyklus bez transportu.
 *
 * Tři rozhodnutí, na kterých vrstva stojí:
 *
 * 1. **Záměr zakládá nárok teprve PŘIJETÍM.** Stav `accepted` smí nastavit jen
 *    zápis výsledku od ČSSZ s dnem doručení; do té doby se sleva neuplatní.
 *    § 7a odst. 5 mluví o okamžiku doručení, ne o tom, že jsme něco připravili.
 * 2. **Souběh u téhož zaměstnavatele hlídáme, souběh u JINÉHO zaměstnavatele
 *    ne.** § 7a odst. 2 věta druhá zakazuje slevu z více zaměstnání u téhož
 *    zaměstnavatele, a to zjistit umíme. Kdo oznámil první napříč
 *    zaměstnavateli (§ 7a odst. 5 věta třetí), ví podle § 23f odst. 1 a 2
 *    jedině ČSSZ — odhadovat to by znamenalo tvrdit nárok, který nemusí být.
 * 3. **Důvod slevy se do oznámení nedává, ale eviduje se.** § 23e odst. 1 pouští
 *    do OZUSPOJ jen údaje podle § 23f odst. 3 písm. a) a b); důvod podle
 *    § 23d odst. 1 písm. b) přesto musí zaměstnavatel mít ve své evidenci,
 *    takže se ukládá do záměru.
 */
final readonly class OzuspojIntentService
{
    public function __construct(
        private PayrollDiscountIntentRepository $intents,
        private OzuspojDeadlinePolicy $deadlines,
        private OzuspojClaimDeadlinePolicy $claimDeadlines,
        private ClockInterface $clock,
    ) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function list(
        int $supplierId,
        string $environment,
        ?int $employmentId = null,
    ): array {
        return array_map(
            fn (array $row): array => $this->describe($row),
            $this->intents->listForSupplier(
                $supplierId,
                $environment,
                $employmentId,
            ),
        );
    }

    /** @return array<string,mixed> */
    public function create(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $intentFrom,
        ?string $employeeInformedOn,
        int $createdBy,
    ): array {
        $context = $this->requireContext($supplierId, $employmentId, $intentFrom);
        $reason = SocialPartTimeDiscountReason::tryFrom(
            is_string($context['social_part_time_discount_reason'] ?? null)
                ? $context['social_part_time_discount_reason']
                : '',
        );
        if ($reason === null) {
            throw new OzuspojException(
                'ozuspoj_discount_reason_missing',
                'Pracovní vztah nemá k tomuhle dni vyplněný důvod slevy podle § 7a odst. 1. Doplňte ho v kartě vztahu a záměr založte znovu.',
            );
        }
        $employmentStart = $this->employmentStart($context);
        if ($intentFrom < $employmentStart) {
            throw new OzuspojException(
                'ozuspoj_intent_before_employment',
                'Záměr nelze oznámit ke dni před vznikem pracovního vztahu.',
            );
        }
        $endDate = $context['end_date'] ?? null;
        if (is_string($endDate) && $endDate !== '' && $intentFrom > $endDate) {
            throw new OzuspojException(
                'ozuspoj_intent_after_employment',
                'Záměr nelze oznámit ke dni po skončení pracovního vztahu.',
            );
        }
        $employeeId = (int) $context['employee_id'];
        $overlapping = $this->intents->overlappingForEmployee(
            $supplierId,
            $environment,
            $employeeId,
            $intentFrom,
            null,
        );
        if ($overlapping !== []) {
            throw new OzuspojException(
                'ozuspoj_intent_overlaps',
                'Za tuhle osobu už je na překrývající se období evidovaný záměr. Slevu lze podle § 7a odst. 2 uplatnit jen z jednoho zaměstnání u téhož zaměstnavatele — ukončete nejdřív ten stávající.',
            );
        }
        $osszCode = $this->osszCode($context);
        $window = $this->deadlines->forIntentStart($intentFrom);
        if ($employeeInformedOn !== null) {
            $this->assertDate($employeeInformedOn);
        }
        $id = $this->intents->insert(
            $supplierId,
            $environment,
            $employeeId,
            $employmentId,
            $reason->value,
            $intentFrom,
            $osszCode,
            $employeeInformedOn,
            $createdBy,
        );
        $stored = $this->intents->find($supplierId, $environment, $id);
        if ($stored === null) {
            throw new OzuspojException(
                'ozuspoj_intent_not_stored',
                'Záměr se nepodařilo uložit.',
            );
        }

        return $this->describe($stored) + ['window' => [
            'earliest_notification_on' => $window->earliestNotificationOn,
            'due_on' => $window->dueOn,
        ]];
    }

    /**
     * Zápis výsledku od ČSSZ. `acceptedOn` je den DORUČENÍ oznámení — bere se
     * z protokolu, ne z hodin serveru, protože právě on rozhoduje o nároku
     * i o pořadí mezi zaměstnavateli.
     *
     * @return array<string,mixed>
     */
    public function recordReceipt(
        int $supplierId,
        string $environment,
        int $intentId,
        string $outcome,
        ?string $acceptedOn,
        ?string $reason,
    ): array {
        $row = $this->requireIntent($supplierId, $environment, $intentId);
        $status = OzuspojIntentStatus::from((string) $row['status']);
        $rowVersion = (int) $row['row_version'];
        $changes = match ($outcome) {
            'accepted' => $this->acceptanceChanges($row, $status, $acceptedOn),
            'rejected' => $this->rejectionChanges($status, $reason),
            'ended' => $this->endChanges($row, $status, $acceptedOn),
            'cancelled' => $this->cancellationChanges($status),
            default => throw new OzuspojException(
                'ozuspoj_receipt_outcome_unknown',
                'Neznámý výsledek zpracování oznámení záměru.',
            ),
        };
        if (!$this->intents->update(
            $supplierId,
            $environment,
            $intentId,
            $rowVersion,
            $changes,
        )) {
            throw new OzuspojException(
                'ozuspoj_intent_conflict',
                'Záměr mezitím někdo změnil. Načtěte ho znovu a akci zopakujte.',
            );
        }

        return $this->describe(
            $this->requireIntent($supplierId, $environment, $intentId),
        );
    }

    /**
     * Zapíše den, ke kterému zaměstnavatel uplatňování slevy končí. Vlastní
     * oznámení se pak připraví jako podání typu 2; teprve jeho přijetí záměr
     * uzavře. Lhůta je podle § 23e odst. 2 osm dnů po skončení měsíce, ve
     * kterém byla sleva uplatněna naposledy.
     *
     * @return array<string,mixed>
     */
    public function requestEnd(
        int $supplierId,
        string $environment,
        int $intentId,
        string $intentTo,
    ): array {
        $row = $this->requireIntent($supplierId, $environment, $intentId);
        $status = OzuspojIntentStatus::from((string) $row['status']);
        if ($status !== OzuspojIntentStatus::Accepted) {
            throw new OzuspojException(
                'ozuspoj_intent_not_accepted',
                'Ukončit lze jen záměr, který ČSSZ přijala.',
            );
        }
        $this->assertDate($intentTo);
        if ($intentTo < (string) $row['intent_from']) {
            throw new OzuspojException(
                'ozuspoj_intent_period_invalid',
                'Den skončení záměru nesmí předcházet dni jeho zahájení.',
            );
        }
        if (!$this->intents->update(
            $supplierId,
            $environment,
            $intentId,
            (int) $row['row_version'],
            ['intent_to' => $intentTo],
        )) {
            throw new OzuspojException(
                'ozuspoj_intent_conflict',
                'Záměr mezitím někdo změnil. Načtěte ho znovu a akci zopakujte.',
            );
        }

        return $this->describe(
            $this->requireIntent($supplierId, $environment, $intentId),
        );
    }

    /** @return array<string,mixed> */
    public function requireIntent(
        int $supplierId,
        string $environment,
        int $intentId,
    ): array {
        $row = $this->intents->find($supplierId, $environment, $intentId);
        if ($row === null) {
            throw new \OutOfBoundsException('Záměr uplatňovat slevu nebyl nalezen.');
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function requireContext(
        int $supplierId,
        int $employmentId,
        string $onDate,
    ): array {
        $this->assertDate($onDate);
        $context = $this->intents->findEmploymentContext(
            $supplierId,
            $employmentId,
            $onDate,
        );
        if ($context === null) {
            throw new \OutOfBoundsException('Pracovní vztah nebyl nalezen.');
        }
        if ((string) $context['relation_type'] !== 'employment') {
            throw new OzuspojException(
                'ozuspoj_relation_type_unsupported',
                'Sleva podle § 7a náleží jen za zaměstnance v pracovním nebo služebním poměru; za tenhle vztah ji oznámit nelze.',
            );
        }

        return $context;
    }

    /** @param array<string,mixed> $context */
    public function osszCode(array $context): int
    {
        $raw = $context['employer_ossz_code'] ?? null;
        $digits = preg_replace('/\D/', '', is_string($raw) ? $raw : '') ?? '';
        $code = $digits === '' ? 0 : (int) $digits;
        if ($code < 100 || $code > 999) {
            throw new OzuspojException(
                'ozuspoj_ossz_code_missing',
                'Firma nemá vyplněný kód místně příslušné OSSZ. Doplňte ho v Nastavení → Firma a oznámení podejte znovu.',
            );
        }

        return $code;
    }

    /** @param array<string,mixed> $context */
    public function employmentStart(array $context): string
    {
        foreach (['actual_start_date', 'start_date'] as $key) {
            $value = $context[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        throw new OzuspojException(
            'ozuspoj_employment_start_missing',
            'Pracovní vztah nemá datum nástupu; bez něj nelze určit, od kdy lze záměr oznámit.',
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function acceptanceChanges(
        array $row,
        OzuspojIntentStatus $status,
        ?string $acceptedOn,
    ): array {
        if ($status !== OzuspojIntentStatus::Submitted) {
            throw new OzuspojException(
                'ozuspoj_intent_not_submitted',
                'Přijetí lze zapsat jen k záměru, který byl podán.',
            );
        }
        if ($acceptedOn === null) {
            throw new OzuspojException(
                'ozuspoj_accepted_on_required',
                'Zapsat přijetí lze jen se dnem doručení oznámení ČSSZ — na něm podle § 7a odst. 5 stojí nárok.',
            );
        }
        $this->assertDate($acceptedOn);
        if ($acceptedOn > $this->today()) {
            throw new OzuspojException(
                'ozuspoj_accepted_on_in_future',
                'Den doručení oznámení nemůže být v budoucnosti.',
            );
        }
        $intentFrom = (string) $row['intent_from'];
        $earliest = $this->deadlines->forIntentStart($intentFrom)
            ->earliestNotificationOn;
        if ($acceptedOn < $earliest) {
            throw new OzuspojException(
                'ozuspoj_accepted_on_too_early',
                'Podle § 7a odst. 5 lze záměr oznámit nejdříve měsíc přede dnem, od kterého se sleva uplatní. Dřívější doručení ČSSZ nezaeviduje.',
            );
        }

        return ['status' => 'accepted', 'accepted_on' => $acceptedOn];
    }

    /** @return array<string,mixed> */
    private function rejectionChanges(
        OzuspojIntentStatus $status,
        ?string $reason,
    ): array {
        if ($status !== OzuspojIntentStatus::Submitted) {
            throw new OzuspojException(
                'ozuspoj_intent_not_submitted',
                'Odmítnutí lze zapsat jen k záměru, který byl podán.',
            );
        }
        $text = $reason === null ? '' : trim($reason);
        if ($text === '') {
            throw new OzuspojException(
                'ozuspoj_rejection_reason_required',
                'U odmítnutého záměru musí zůstat důvod z protokolu ČSSZ — bez něj nikdo nepozná, jestli byl první jiný zaměstnavatel.',
            );
        }

        return [
            'status' => 'rejected',
            'rejection_reason' => mb_substr($text, 0, 190),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function endChanges(
        array $row,
        OzuspojIntentStatus $status,
        ?string $acceptedOn,
    ): array {
        if ($status !== OzuspojIntentStatus::Accepted) {
            throw new OzuspojException(
                'ozuspoj_intent_not_accepted',
                'Skončení lze zapsat jen k přijatému záměru.',
            );
        }
        $intentTo = $row['intent_to'] ?? null;
        if (!is_string($intentTo) || $intentTo === '') {
            throw new OzuspojException(
                'ozuspoj_intent_to_required',
                'Nejdřív zadejte den, ke kterému uplatňování slevy končí.',
            );
        }
        if ($acceptedOn === null) {
            throw new OzuspojException(
                'ozuspoj_accepted_on_required',
                'Zapsat skončení lze jen se dnem doručení oznámení ČSSZ.',
            );
        }
        $this->assertDate($acceptedOn);

        return [
            'status' => 'ended',
            'ended_accepted_on' => $acceptedOn,
        ];
    }

    /** @return array<string,mixed> */
    private function cancellationChanges(OzuspojIntentStatus $status): array
    {
        if ($status === OzuspojIntentStatus::Ended
            || $status === OzuspojIntentStatus::Cancelled
        ) {
            throw new OzuspojException(
                'ozuspoj_intent_already_closed',
                'Záměr je už uzavřený.',
            );
        }

        return [
            'status' => 'cancelled',
            'accepted_on' => null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function describe(array $row): array
    {
        $status = OzuspojIntentStatus::from((string) $row['status']);
        $intentFrom = (string) $row['intent_from'];
        $window = $this->deadlines->forIntentStart($intentFrom);
        $periodStart = substr($intentFrom, 0, 7) . '-01';

        return [
            'id' => (int) $row['id'],
            'employment_id' => (int) $row['employment_id'],
            'employee_id' => (int) $row['employee_id'],
            'employee_name' => (string) ($row['full_name'] ?? ''),
            'discount_reason' => (string) $row['discount_reason'],
            'intent_from' => $intentFrom,
            'intent_to' => $row['intent_to'],
            'status' => $status->value,
            'accepted_on' => $row['accepted_on'],
            'ended_accepted_on' => $row['ended_accepted_on'],
            'rejection_reason' => $row['rejection_reason'],
            'employee_informed_on' => $row['employee_informed_on'],
            'ossz_code' => (int) $row['ossz_code'],
            'row_version' => (int) $row['row_version'],
            'evidences_discount' => $status->isEvidenced()
                && $row['accepted_on'] !== null,
            'earliest_notification_on' => $window->earliestNotificationOn,
            'notification_due_on' => $window->dueOn,
            'transitional_q1_2026' =>
                $this->claimDeadlines->isTransitionalQ12026($periodStart),
        ];
    }

    private function assertDate(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            throw new OzuspojException(
                'ozuspoj_date_invalid',
                'Datum musí být ve tvaru RRRR-MM-DD.',
            );
        }
    }

    private function today(): string
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('Europe/Prague'))
            ->format('Y-m-d');
    }
}
