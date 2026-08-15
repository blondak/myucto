<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Co plyne z výzvy k odstranění vad podle § 74 daňového řádu.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co říká zákon
 * ═══════════════════════════════════════════════════════════════════════════
 * § 74 zák. 280/2009 Sb.:
 *   odst. 1 — správce daně vyzve podatele, aby **ve stanovené lhůtě** odstranil
 *             vady podání (písmena a–d, {@see DefectGround}),
 *   odst. 2 — výzva obsahuje poučení o následcích neodstranění,
 *   odst. 3 — **budou-li vady odstraněny ve stanovené lhůtě, hledí se na podání,
 *             jako by bylo učiněno řádně a včas**,
 *   odst. 4 — nebudou-li vady podle odst. 1 písm. a) nebo b) ve stanovené lhůtě
 *             odstraněny, **stává se podání uplynutím této lhůty neúčinným**;
 *             správce daně o tom pořídí úřední záznam a vyrozumí podatele.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ Lhůtu si aplikace NEVYMÝŠLÍ
 * ═══════════════════════════════════════════════════════════════════════════
 * § 74 žádnou délku nestanoví — lhůtu určuje správce daně rozhodnutím (§ 32
 * odst. 1 DŘ) a jediné zákonné omezení je, že kratší než 8 dnů ji lze stanovit
 * „jen zcela výjimečně pro úkony jednoduché a zvlášť naléhavé" (§ 32 odst. 2).
 * Není z čeho odvodit obvyklý termín, takže se nedopočítává:
 *
 *   - datum ve výzvě         → použije se (`stated_in_notice`),
 *   - počet dnů ve výzvě     → spočítá se od doručení (`derived_from_days`),
 *   - ani jedno              → **termín není** a stav je
 *                              {@see DefectNoticeStatus::Unknown}.
 *
 * „Neznáme termín" tedy nikdy nevede na „stihli jsme to". Vede na výzvu
 * uživateli, ať termín z papíru doplní.
 *
 * Osmidenní minimum slouží jen k UPOZORNĚNÍ ({@see DefectNoticeAssessment::$suspiciouslyShortPeriod}).
 * Kratší lhůta je zákonná, jen výjimečná — odmítnout ji evidovat by znamenalo
 * zahodit výzvu, která reálně přišla.
 *
 * ── Konec lhůty a dny pracovního klidu ──────────────────────────────────────
 * § 33 odst. 4 DŘ: připadne-li poslední den lhůty na sobotu, neděli nebo svátek,
 * je posledním dnem nejblíže následující pracovní den. Platí to i na datum
 * napsané přímo ve výzvě — proto se posouvá i `stated_in_notice`, ne jen
 * dopočítaný termín.
 */
final readonly class DefectNoticeAssessor
{
    public function __construct(private SubmissionLegalRules $rules) {}

    /**
     * @param ?\DateTimeImmutable $deliveredOn rozhodný den doručení výzvy
     * @param ?\DateTimeImmutable $statedRespondBy datum uvedené přímo ve výzvě
     * @param ?int $statedPeriodDays počet dnů uvedený ve výzvě
     * @param ?\DateTimeImmutable $respondedOn kdy jsme vadu odstranili
     * @param bool $withdrawn správce daně výzvu vzal zpět
     */
    public function assess(
        ?\DateTimeImmutable $deliveredOn,
        ?\DateTimeImmutable $statedRespondBy,
        ?int $statedPeriodDays,
        DefectGround $ground,
        ?\DateTimeImmutable $respondedOn,
        \DateTimeImmutable $today,
        bool $withdrawn = false,
    ): DefectNoticeAssessment {
        $today = self::day($today);
        $consequence = $ground->consequence();
        $deadline = $this->deadline($deliveredOn, $statedRespondBy, $statedPeriodDays);

        if ($withdrawn) {
            return new DefectNoticeAssessment(
                DefectNoticeStatus::Withdrawn,
                $consequence,
                DefectNoticeOutcome::Unknown,
                $deadline['on'],
                $deadline['source'],
                $deadline['shifted'],
                null,
                'Výzva je vedená jako vzatá zpět, lhůta z ní neběží.',
                $deadline['short'],
            );
        }

        // ── Bez termínu se nedá tvrdit vůbec nic ──
        if ($deadline['on'] === null) {
            return new DefectNoticeAssessment(
                DefectNoticeStatus::Unknown,
                $consequence,
                DefectNoticeOutcome::Unknown,
                null,
                'unknown',
                false,
                null,
                $deliveredOn === null
                    ? 'U výzvy chybí den doručení i lhůta k odpovědi. Dokud je nedoplníte z papíru, '
                      . 'aplikace nemůže říct, do kdy je potřeba reagovat — a mlčení tady neznamená, že je čas.'
                    : 'Výzva neuvádí, do kdy máte vadu odstranit. Lhůtu stanoví správce daně ve výzvě '
                      . '(§ 32 odst. 1 daňového řádu) a aplikace si ji nedomýšlí. Opište termín z papíru.',
                false,
            );
        }

        $due = $deadline['on'];
        $daysLeft = (int) $today->diff($due)->format('%r%a');

        if ($respondedOn !== null) {
            $responded = self::day($respondedOn);
            $inTime = $responded <= $due;

            return new DefectNoticeAssessment(
                $inTime ? DefectNoticeStatus::AnsweredInTime : DefectNoticeStatus::AnsweredLate,
                $consequence,
                $inTime ? DefectNoticeOutcome::Cured : $this->missedOutcome($consequence),
                $due,
                $deadline['source'],
                $deadline['shifted'],
                $daysLeft,
                $inTime
                    ? 'Vada byla odstraněna ' . $responded->format('j. n. Y') . ', tedy ve lhůtě. '
                      . 'Na podání se hledí, jako by bylo učiněno řádně a včas (§ 74 odst. 3 daňového řádu).'
                    : 'Odpověď přišla ' . $responded->format('j. n. Y') . ', až po lhůtě, která skončila '
                      . $due->format('j. n. Y') . '. Účinky § 74 odst. 3 daňového řádu tím nenastaly. '
                      . $this->consequenceSentence($consequence),
                $deadline['short'],
            );
        }

        if ($due < $today) {
            return new DefectNoticeAssessment(
                DefectNoticeStatus::Missed,
                $consequence,
                $this->missedOutcome($consequence),
                $due,
                $deadline['source'],
                $deadline['shifted'],
                $daysLeft,
                'Lhůta k odstranění vady uplynula ' . $due->format('j. n. Y') . ' a odpověď evidovanou nemáme. '
                . $this->consequenceSentence($consequence),
                $deadline['short'],
            );
        }

        return new DefectNoticeAssessment(
            DefectNoticeStatus::Open,
            $consequence,
            DefectNoticeOutcome::Unknown,
            $due,
            $deadline['source'],
            $deadline['shifted'],
            $daysLeft,
            'Vadu je potřeba odstranit do ' . $due->format('j. n. Y')
            . ($daysLeft === 0 ? ' — to je dnes.' : ' (zbývá dnů: ' . $daysLeft . ').')
            . ' ' . $this->consequenceSentence($consequence),
            $deadline['short'],
        );
    }

    /**
     * @return array{on:?\DateTimeImmutable,source:string,shifted:bool,short:bool}
     */
    private function deadline(
        ?\DateTimeImmutable $deliveredOn,
        ?\DateTimeImmutable $statedRespondBy,
        ?int $statedPeriodDays,
    ): array {
        // Datum napsané ve výzvě má přednost — je to rozhodnutí správce daně,
        // ne náš výpočet.
        if ($statedRespondBy !== null) {
            $raw = self::day($statedRespondBy);
            $due = CzechWorkingDays::shiftToWorkingDay($raw);

            return [
                'on' => $due,
                'source' => 'stated_in_notice',
                'shifted' => $raw->format('Y-m-d') !== $due->format('Y-m-d'),
                'short' => $this->isShort($deliveredOn, $due),
            ];
        }

        if ($statedPeriodDays !== null && $statedPeriodDays > 0 && $deliveredOn !== null) {
            // § 33 odst. 2 DŘ — lhůta podle dnů běží ode dne následujícího po doručení.
            $raw = self::day($deliveredOn)->modify('+' . $statedPeriodDays . ' days');
            $due = CzechWorkingDays::shiftToWorkingDay($raw);
            $minimum = $this->rules->defectNoticeMinimumPeriodDays(self::day($deliveredOn)->format('Y-m-d'));

            return [
                'on' => $due,
                'source' => 'derived_from_days',
                'shifted' => $raw->format('Y-m-d') !== $due->format('Y-m-d'),
                'short' => $statedPeriodDays < $minimum->value,
            ];
        }

        return ['on' => null, 'source' => 'unknown', 'shifted' => false, 'short' => false];
    }

    private function isShort(?\DateTimeImmutable $deliveredOn, \DateTimeImmutable $due): bool
    {
        if ($deliveredOn === null) {
            return false;
        }
        $minimum = $this->rules->defectNoticeMinimumPeriodDays(self::day($deliveredOn)->format('Y-m-d'));
        $days = (int) self::day($deliveredOn)->diff($due)->format('%r%a');

        return $days < $minimum->value;
    }

    private function missedOutcome(DefectConsequence $consequence): DefectNoticeOutcome
    {
        return match ($consequence) {
            DefectConsequence::Ineffective => DefectNoticeOutcome::Ineffective,
            DefectConsequence::NoIneffectiveness => DefectNoticeOutcome::PenaltyRisk,
            DefectConsequence::Unknown => DefectNoticeOutcome::Unknown,
        };
    }

    private function consequenceSentence(DefectConsequence $consequence): string
    {
        return match ($consequence) {
            DefectConsequence::Ineffective =>
                'Neodstraněná vada podle § 74 odst. 1 písm. a) nebo b) daňového řádu znamená, '
                . 'že se podání uplynutím lhůty stává neúčinným — právně tedy jako by nebylo podáno.',
            DefectConsequence::NoIneffectiveness =>
                'U vady podle § 74 odst. 1 písm. c) nebo d) daňového řádu podání neúčinným nebude, '
                . 'ale za nesprávný způsob či formát hrozí pokuta podle § 247a daňového řádu.',
            DefectConsequence::Unknown =>
                'Z evidence nejde poznat, které písmeno § 74 odst. 1 daňového řádu výzva uvádí, '
                . 'takže nelze říct, jestli podání hrozí neúčinnost. Doplňte to z papíru.',
        };
    }

    private static function day(\DateTimeImmutable $value): \DateTimeImmutable
    {
        return $value->setTimezone(new \DateTimeZone('Europe/Prague'))->setTime(0, 0);
    }
}
