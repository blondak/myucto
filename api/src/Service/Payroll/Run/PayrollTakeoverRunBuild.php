<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Hotový podklad převzatého běhu: dva zmrazené snímky a doložení plateb.
 *
 * Vzniká čistě z převzatých dat ({@see PayrollTakeoverRunBuilder}) a nic
 * nezapisuje — zápis dělá {@see PayrollTakeoverRunService}. Oddělení je
 * záměrné: sestavení je testovatelné bez databáze, takže tvrzení „převzatý
 * výsledek se NEPOČÍTÁ" se dá ověřit přímo, ne jen přes chování endpointu.
 */
final readonly class PayrollTakeoverRunBuild
{
    /**
     * @param array<string,mixed> $inputSnapshot
     * @param array<string,mixed> $resultSnapshot
     * @param list<array{
     *   evidence_kind:string,
     *   certainty:string,
     *   employee_id:?int,
     *   external_person_ref:string,
     *   amount_minor:int,
     *   paid_on:?string
     * }> $paymentEvidence
     * @param list<string> $sources
     */
    public function __construct(
        public string $periodStart,
        public string $paymentDate,
        public array $inputSnapshot,
        public string $inputSnapshotHash,
        public array $resultSnapshot,
        public string $resultSnapshotHash,
        public array $paymentEvidence,
        public array $sources,
        public int $employeeCount,
        public int $relationshipCount,
    ) {}
}
