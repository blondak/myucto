<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

use MyInvoice\Repository\Payroll\PayrollOvertimeRepository;
use MyInvoice\Service\Payroll\Run\PayrollRunValidation;

/**
 * Spojka mezi evidencí přesčasu, rulesetem a čistou logikou § 93.
 *
 * ─── PROČ VARUJE A NEBLOKUJE ────────────────────────────────────────────────
 *
 * Překročení limitu podle § 93 je právní vada NA STRANĚ ZAMĚSTNAVATELE, ne vada
 * výpočtu. Přesčas, který zaměstnanec odpracoval, mu zaměstnavatel musí podle
 * § 114 zaplatit i tehdy, když ho nařídil nad zákonný rozsah — neplatnost
 * příkazu nemá za následek, že se odvedená práce neproplácí. Zastavit kvůli
 * tomu výplatu by tedy k první vadě přidalo druhou, těžší, a poškodilo by to
 * jedinou osobu, která na porušení nemá vinu.
 *
 * Proto všechny nálezy jdou do `payroll_run_validations` jako `warning`
 * s `requires_override = false` a jako `info` u včasného upozornění:
 *
 *   • `blocker` je vyloučený — {@see \MyInvoice\Service\Payroll\Run\PayrollRunWorkflow}
 *     na něm zastaví příkaz `approve`, tedy přesně výplatu.
 *   • `requires_override = true` je vyloučené TAKÉ, a to z praktického důvodu:
 *     workflow na nevyřešeném overridu schválení rovněž zastaví, ale sloupce
 *     `override_reason` / `overridden_by` / `overridden_at` v tabulce od migrace
 *     1210 nikdo nenastavuje — není pro ně route ani obrazovka. Varování
 *     vyžadující override by tak bylo blokerem, který nejde odblokovat.
 *
 * Doloženost se tím neztrácí: validace se ukládá k REVIZI běhu, takže u každé
 * schválené revize navždy zůstane, že se na překročení v ten okamžik
 * upozorňovalo. Včasnost řeší docházka — assessment se počítá i pro otevřený
 * měsíc, takže mzdová účetní vidí stav dřív, než běh vůbec vznikne.
 */
final class PayrollOvertimeLimitService
{
    private readonly OvertimeLimitEvaluator $evaluator;

    public function __construct(
        private readonly PayrollOvertimeRepository $repository,
        private readonly OvertimeLimitRules $rules,
        ?OvertimeLimitEvaluator $evaluator = null,
    ) {
        $this->evaluator = $evaluator ?? new OvertimeLimitEvaluator();
    }

    /**
     * @param array<int,?string> $employmentStarts začátek vztahu podle ID, kvůli
     *        kratšímu vyrovnávacímu období na začátku pracovního poměru
     * @param list<int> $employmentIds
     * @return array<int,OvertimeLimitAssessment>
     */
    public function assessMany(
        int $supplierId,
        array $employmentIds,
        string $periodStart,
        string $periodEnd,
        array $employmentStarts = [],
    ): array {
        if ($employmentIds === []) {
            return [];
        }
        $limits = $this->rules->forDate($periodStart);
        $from = self::historyStart($periodStart, $periodEnd, $limits);
        $segments = $this->repository->segmentsForMany(
            $supplierId,
            $employmentIds,
            $from,
            $periodEnd,
        );
        $consents = $this->repository->consentsForMany($supplierId, $employmentIds);

        $result = [];
        foreach ($employmentIds as $employmentId) {
            $result[$employmentId] = $this->evaluator->assess(
                $employmentId,
                $periodStart,
                $periodEnd,
                $segments[$employmentId] ?? [],
                $consents[$employmentId] ?? [],
                $limits,
                $employmentStarts[$employmentId] ?? null,
            );
        }

        return $result;
    }

    /** @return list<PayrollRunValidation> */
    public function validations(OvertimeLimitAssessment $assessment): array
    {
        $validations = [];
        foreach ($assessment->findings as $finding) {
            $validations[] = new PayrollRunValidation(
                $finding->severity,
                $finding->code,
                'employment',
                $assessment->employmentId,
                $finding->message,
                '/payroll/time',
                false,
            );
        }

        return $validations;
    }

    /**
     * Nejstarší den, který musí být v podkladech: buď začátek kalendářního roku
     * (kvůli ročnímu limitu), nebo začátek nejdelšího možného vyrovnávacího
     * období, podle toho, co je dřív.
     */
    private static function historyStart(
        string $periodStart,
        string $periodEnd,
        OvertimeLimits $limits,
    ): string {
        $yearStart = substr($periodStart, 0, 4) . '-01-01';
        $end = new \DateTimeImmutable($periodEnd, new \DateTimeZone('UTC'));
        $windowStart = $end
            ->modify('-' . ((int) $end->format('N') - 1) . ' days')
            ->modify(sprintf('-%d days', 7 * $limits->averagingMaxWeeks))
            ->format('Y-m-d');

        return min($yearStart, $windowStart);
    }
}
