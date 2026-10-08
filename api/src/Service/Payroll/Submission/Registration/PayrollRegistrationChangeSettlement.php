<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Repository\Payroll\PayrollRegistrationSubmissionRepository;

/**
 * Odstup mezi přijatou změnou údajů zaměstnance (REGZEC A3) a následným
 * jednotným měsíčním hlášením (JMHZ).
 *
 * FAQ ČSSZ k JMHZ (9. 6. 2026, chyba 243, bod B): po podání A3, zejména
 * s daňovou rezidencí (10068) a oprávněním (10459), je nutné vyčkat nejméně
 * 48 hodin, než jsou data propsána do všech systémů; hlášení odeslané dřív
 * ČSSZ může zamítnout. Pravidlo je k zaměstnanci, ne k firmě, proto se hlídají
 * jen vztahy, které hlášení skutečně obsahuje.
 */
class PayrollRegistrationChangeSettlement
{
    public const WAIT_HOURS = 48;

    public function __construct(
        private readonly PayrollRegistrationSubmissionRepository $registrations,
    ) {}

    /**
     * Kdy nejdřív smí hlášení odejít, nebo null, když nic nečeká.
     *
     * @param list<int> $employmentIds
     * @return array{until:\DateTimeImmutable,employment_ids:list<int>}|null
     */
    public function pendingUntil(
        int $supplierId,
        string $environment,
        array $employmentIds,
        \DateTimeImmutable $now,
    ): ?array {
        $utc = new \DateTimeZone('UTC');
        $notBefore = $now->setTimezone($utc)
            ->modify('-' . self::WAIT_HOURS . ' hours');
        $recent = $this->registrations->employmentsWithAcceptedChangeSince(
            $supplierId,
            $environment,
            $employmentIds,
            $notBefore->format('Y-m-d H:i:s'),
        );
        if ($recent === []) {
            return null;
        }
        $latest = max($recent);
        $until = (new \DateTimeImmutable($latest, $utc))
            ->modify('+' . self::WAIT_HOURS . ' hours');

        return ['until' => $until, 'employment_ids' => array_keys($recent)];
    }

    /** Věta pro účetní. */
    public static function message(\DateTimeImmutable $until, int $employments): string
    {
        return 'U ' . $employments . ' pracovních vztahů v tomto hlášení ČSSZ '
            . 'za posledních ' . self::WAIT_HOURS . ' hodin přijala změnu údajů '
            . 'zaměstnance (REGZEC A3). Data se do systémů ČSSZ propisují až po '
            . 'dvou dnech a dřív odeslané hlášení může ČSSZ zamítnout (chyba 243), '
            . 'proto se nepřipravuje. Připravte ho znovu po '
            . $until->setTimezone(new \DateTimeZone('Europe/Prague'))
                ->format('j. n. Y H:i') . '.';
    }
}
