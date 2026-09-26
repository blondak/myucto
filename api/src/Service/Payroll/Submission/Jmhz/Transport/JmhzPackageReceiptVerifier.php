<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Service\Payroll\Submission\PayrollReceiptVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceipt;

/**
 * Protokol jednoho dílčího balíku rozděleného hlášení JMHZ.
 *
 * Každý balík je u ČSSZ samostatné dílčí podání s vlastním protokolem, ale
 * v aplikaci je to jedno podání. Stav podání proto neurčuje jediný protokol:
 * skládá se z posledních ověřených protokolů všech balíků
 * ({@see self::aggregate()}). Ověření podpisu i CorrelationID dělá vnitřní
 * verifier beze změny; tahle vrstva jen doplní stav součásti balíku
 * a složený stav podání.
 */
final readonly class JmhzPackageReceiptVerifier implements PayrollReceiptVerifierInterface
{
    /**
     * @param \Closure(int,string,int):array<int,string> $storedStatuses
     *        stav posledního ověřeného protokolu každé součásti podání
     * @param array<int,int> $packageOrdinals id součásti → pořadí balíku
     */
    public function __construct(
        private PayrollReceiptVerifierInterface $inner,
        private \Closure $storedStatuses,
        private int $supplierId,
        private string $environment,
        private int $submissionId,
        private int $partId,
        private array $packageOrdinals,
    ) {}

    public function verify(
        string $bytes,
        string $channel,
        string $environment,
        ?string $expectedCorrelationReference,
    ): PayrollVerifiedReceipt {
        $verified = $this->inner->verify($bytes, $channel, $environment, $expectedCorrelationReference);
        $statuses = [];
        foreach (($this->storedStatuses)($this->supplierId, $this->environment, $this->submissionId) as $partId => $status) {
            if (isset($this->packageOrdinals[$partId])) {
                $statuses[$this->packageOrdinals[$partId]] = $status;
            }
        }
        $statuses[$this->packageOrdinals[$this->partId]] = $verified->remoteStatus;
        foreach ($this->packageOrdinals as $ordinal) {
            $statuses[$ordinal] ??= null;
        }

        return new PayrollVerifiedReceipt(
            self::aggregate($statuses),
            $verified->correlationReference,
            $verified->partStatuses + [$this->partId => self::partStatus($verified->remoteStatus)],
            $verified->formOutcomes,
        );
    }

    /**
     * Stav podání ze stavů balíků (pořadí → stav posledního ověřeného
     * protokolu, `null` = protokol ještě není).
     *
     * - Zamítnutý první balík: ČSSZ bez něj ostatní dílčí podání nezpracuje
     *   (katalog kontrol: „1. dílčí podání nebylo přijato do zpracování"),
     *   celé podání je zamítnuté.
     * - Chybí protokol některého balíku: podání se dál zpracovává.
     * - Všechny přijaté: přijato.
     * - Jinak (některý balík zamítnutý nebo přijatý jen zčásti): přijato
     *   částečně, formuláře k opravě jsou ve výsledcích formulářů.
     *
     * @param array<int,?string> $statuses
     */
    public static function aggregate(array $statuses): string
    {
        ksort($statuses);
        if (($statuses[1] ?? null) === 'rejected') {
            return 'rejected';
        }
        if (in_array(null, $statuses, true)) {
            return 'processing';
        }
        $distinct = array_values(array_unique($statuses));
        if ($distinct === ['accepted']) {
            return 'accepted';
        }
        if (array_intersect($distinct, ['rejected', 'partially_accepted', 'correction_required']) !== []) {
            return 'partially_accepted';
        }
        if (in_array('waiting_for_identity', $distinct, true)) {
            return 'waiting_for_identity';
        }

        return 'processing';
    }

    /** Stav součásti podání (ENUM součástí nezná „částečně" ani čekání na identitu). */
    private static function partStatus(string $remoteStatus): string
    {
        return match ($remoteStatus) {
            'accepted', 'partially_accepted' => 'accepted',
            'rejected' => 'rejected',
            'correction_required' => 'correction_required',
            default => 'processing',
        };
    }
}
