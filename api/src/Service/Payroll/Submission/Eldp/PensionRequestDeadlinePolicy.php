<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Lhůty povinností důchodového pojištění, které vznikají až výzvou nebo
 * žádostí — jediné místo, kde se počítají.
 *
 * Zákon č. 582/1991 Sb., o organizaci a provádění sociálního zabezpečení
 * (znění od 1. 1. 2026), a přechodná ustanovení čl. V zákona č. 360/2025 Sb.:
 *
 * - **evidenční list na výzvu** ČSSZ/ÚSSZ — lhůtu počítá
 *   {@see EldpDeadlinePolicy::forAuthorityRequest()} (§ 38a odst. 2 a 3,
 *   čl. V bod 8, do roku 2025 § 39 odst. 3 ve znění do 31. 12. 2025), list při
 *   úmrtí oznámeném pozůstalým {@see EldpDeadlinePolicy::forDeath()}; tady se
 *   nic nepočítá znovu,
 * - **sdělení nebo oprava údajů měsíčním hlášením na výzvu** — § 38a odst. 1:
 *   do 8 dnů ode dne doručení výzvy,
 * - **potvrzení o době důchodového pojištění v roce** — § 42: zaměstnanci,
 *   bývalému zaměstnanci i územní správě do 8 dnů od obdržení žádosti,
 * - **potvrzení o náhradách za ztrátu na výdělku** — § 37 odst. 2: do 30
 *   kalendářních dnů ode dne doručení žádosti,
 * - **potvrzení podle znění do 31. 12. 2025** — čl. V bod 2 písm. b) až d),
 *   body 3 a 4 zák. č. 360/2025 Sb.: starý § 37 odst. 2 písm. c), odst. 3
 *   a 4 a § 37a, vždy do 30 dnů od žádosti občana.
 *
 * Konec lhůty připadající na sobotu, neděli nebo svátek se posouvá na
 * nejbližší pracovní den, stejně jako u evidenčního listu
 * ({@see EldpDeadlinePolicy}); jinak by tytéž povinnosti měly dva kalendáře.
 */
final class PensionRequestDeadlinePolicy
{
    public const KINDS = [
        'eldp',
        'jmh_correction',
        'insurance_period_confirmation',
        'compensation_confirmation',
        'legacy_confirmation',
    ];

    public const LEGACY_KINDS = ['excluded_periods', 'deep_mining', 'risky_work', 'rescuer'];

    /** Kdo smí povinnost u kterého druhu vyvolat. */
    public const REQUESTERS = [
        'eldp' => ['cssz', 'ossz', 'survivor'],
        'jmh_correction' => ['cssz', 'ossz'],
        'insurance_period_confirmation' => ['employee', 'former_employee', 'ossz'],
        'compensation_confirmation' => ['employee', 'former_employee'],
        'legacy_confirmation' => ['employee', 'former_employee'],
    ];

    /** § 38a odst. 1 a § 42 zákona č. 582/1991 Sb. */
    private const EIGHT_DAYS = 8;

    /** § 37 odst. 2 zákona č. 582/1991 Sb. a čl. V body 2 až 4 zák. č. 360/2025 Sb. */
    private const THIRTY_DAYS = 30;

    /** Poslední rok, za který se potvrzení podle znění do 31. 12. 2025 vydávají. */
    private const LEGACY_LAST_YEAR = 2025;

    public function __construct(
        private readonly EldpDeadlinePolicy $eldp = new EldpDeadlinePolicy(),
    ) {}

    /**
     * @return array{due_on:string,rule:string,legal_basis:string}
     */
    public function forRequest(
        string $kind,
        string $requester,
        string $receivedOn,
        ?int $periodYear = null,
        ?string $statedDueOn = null,
        ?string $deathOn = null,
        ?string $legacyKind = null,
    ): array {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Neznámý druh výzvy nebo žádosti.');
        }
        if (!in_array($requester, self::REQUESTERS[$kind], true)) {
            throw new \InvalidArgumentException('Tento druh výzvy nebo žádosti nemůže podat zvolený žadatel.');
        }
        $received = self::date($receivedOn, 'Den doručení');
        if ($kind !== 'eldp' && $statedDueOn !== null) {
            throw new \InvalidArgumentException('Lhůtu z výzvy lze zadat jen u evidenčního listu; ostatní lhůty určuje zákon.');
        }
        if (($kind === 'legacy_confirmation') !== ($legacyKind !== null)) {
            throw new \InvalidArgumentException('Druh potvrzení podle znění do 31. 12. 2025 patří jen k tomuto druhu žádosti.');
        }

        return match ($kind) {
            'eldp' => $this->eldpRequest($requester, $receivedOn, $periodYear, $statedDueOn, $deathOn),
            'jmh_correction' => self::shifted(
                $received,
                self::EIGHT_DAYS,
                'jmh_correction_within_8_days',
                'Zákon č. 582/1991 Sb., § 38a odst. 1 — chybějící nebo chybně sdělené údaje '
                    . 'zaměstnavatel sdělí nebo opraví měsíčním hlášením do 8 dnů ode dne doručení výzvy.',
            ),
            'insurance_period_confirmation' => self::shifted(
                $received,
                self::EIGHT_DAYS,
                'insurance_period_confirmation_within_8_days',
                'Zákon č. 582/1991 Sb., § 42 — potvrzení o době trvání zaměstnání v kalendářním roce, '
                    . 'po kterou byl zaměstnanec důchodově pojištěn, se vydá do 8 dnů od obdržení žádosti.',
            ),
            'compensation_confirmation' => self::shifted(
                $received,
                self::THIRTY_DAYS,
                'compensation_confirmation_within_30_days',
                'Zákon č. 582/1991 Sb., § 37 odst. 2 — potvrzení o době, důvodu a výši náhrad za ztrátu '
                    . 'na výdělku se vystaví do 30 kalendářních dnů ode dne doručení žádosti.',
            ),
            default => $this->legacy((string) $legacyKind, $received, $periodYear),
        };
    }

    /** @return array{due_on:string,rule:string,legal_basis:string} */
    private function eldpRequest(
        string $requester,
        string $receivedOn,
        ?int $periodYear,
        ?string $statedDueOn,
        ?string $deathOn,
    ): array {
        if ($periodYear === null) {
            throw new \InvalidArgumentException('U evidenčního listu zadejte vykazovaný rok.');
        }
        if ($requester === 'survivor') {
            if ($deathOn === null) {
                throw new \InvalidArgumentException('U evidenčního listu po úmrtí zadejte datum úmrtí.');
            }
            if ($statedDueOn !== null) {
                throw new \InvalidArgumentException('Lhůtu u listu po úmrtí určuje zákon, ne výzva.');
            }
            $window = $this->eldp->forDeath($periodYear, $deathOn);

            return [
                'due_on' => $window->dueOn,
                'rule' => $window->rulesetId,
                'legal_basis' => $window->legalBasis,
            ];
        }
        $window = $this->eldp->forAuthorityRequest($receivedOn, $periodYear, $statedDueOn);

        return [
            'due_on' => $window->dueOn,
            'rule' => $window->rulesetId,
            'legal_basis' => $window->legalBasis,
        ];
    }

    /** @return array{due_on:string,rule:string,legal_basis:string} */
    private function legacy(string $legacyKind, \DateTimeImmutable $received, ?int $periodYear): array
    {
        if (!in_array($legacyKind, self::LEGACY_KINDS, true)) {
            throw new \InvalidArgumentException('Neznámý druh potvrzení podle znění do 31. 12. 2025.');
        }
        if ($periodYear !== null && $periodYear > self::LEGACY_LAST_YEAR) {
            throw new \InvalidArgumentException(
                'Potvrzení podle znění do 31. 12. 2025 se vydává jen za období před 1. 1. 2026 '
                    . '(čl. V bod 2 zákona č. 360/2025 Sb.).',
            );
        }
        if ($legacyKind === 'excluded_periods' && $periodYear === null) {
            throw new \InvalidArgumentException('Potvrzení o vyloučených dobách se vystavuje za jednotlivé kalendářní roky; zadejte rok.');
        }
        $basis = match ($legacyKind) {
            'excluded_periods' => 'čl. V bod 2 písm. b) zákona č. 360/2025 Sb. a § 37 odst. 2 písm. c) '
                . 'zákona č. 582/1991 Sb. ve znění do 31. 12. 2025 — potvrzení o dobách podle § 16 odst. 4 '
                . 'věty druhé písm. a), d) a e) zákona o důchodovém pojištění se vystaví do 30 kalendářních '
                . 'dnů ode dne doručení žádosti.',
            'deep_mining' => 'čl. V bod 2 písm. c) zákona č. 360/2025 Sb. a § 37 odst. 3 zákona '
                . 'č. 582/1991 Sb. ve znění do 31. 12. 2025 — potvrzení o zaměstnání v hlubinném hornictví '
                . 'se vystaví do 30 kalendářních dnů od obdržení žádosti a stejnopis se předloží ČSSZ.',
            'risky_work' => 'čl. V bod 2 písm. d) a bod 4 zákona č. 360/2025 Sb. — potvrzení o počtu směn '
                . 'v rizikovém zaměstnání se vystaví a předloží orgánu sociálního zabezpečení do 30 dnů '
                . 'ode dne obdržení žádosti, stejnopis dostane zaměstnanec.',
            default => 'čl. V bod 3 zákona č. 360/2025 Sb. a § 37a odst. 2 zákona č. 582/1991 Sb. ve znění '
                . 'do 31. 12. 2025 — potvrzení o počtu směn zdravotnického záchranáře nebo člena jednotky '
                . 'hasičského záchranného sboru podniku se vystaví a předloží do 30 dnů od obdržení žádosti.',
        };

        return self::shifted($received, self::THIRTY_DAYS, 'legacy_' . $legacyKind . '_within_30_days', $basis);
    }

    /** @return array{due_on:string,rule:string,legal_basis:string} */
    private static function shifted(\DateTimeImmutable $from, int $days, string $rule, string $basis): array
    {
        return [
            'due_on' => CzechWorkingDays::shiftToWorkingDay($from->modify('+' . $days . ' days'))->format('Y-m-d'),
            'rule' => $rule,
            'legal_basis' => $basis,
        ];
    }

    private static function date(string $value, string $label): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("{$label} není platné datum.");
        }

        return $date;
    }
}
