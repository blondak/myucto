<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentExitRevisionRepository;

/**
 * Dávková orchestrace rendererů nad schválenou revizí mzdového běhu.
 *
 * Smysl není „vygenerovat co nejvíc PDF", ale odpovědět na otázku, jestli je
 * mzdový měsíc dokumentačně úplný. Prázdný archiv proto nikdy nevypadá jako
 * hotový měsíc: dávka vždy vyjmenuje, co chybí a proč, včetně pracovních
 * vztahů skončených v období, ke kterým ještě není vydané povinné potvrzení
 * o zaměstnání podle § 313 odst. 1 zákoníku práce.
 *
 * Potvrzení o průměrném výdělku (§ 313 odst. 2 a § 356) se vydávají jen na
 * žádost zaměstnance a vyžadují podklad, který nelze odvodit z dat, takže je
 * dávka hlásí jako volitelné a nikdy je sama nevystaví.
 */
final class ApprovedRevisionDocumentBatchService
{
    public function __construct(
        private readonly ApprovedRevisionPayslipBatchService $payslips,
        private readonly PayrollDocumentService $documents,
        private readonly PayrollDocumentRepository $documentRepository,
        private readonly PayrollEmploymentExitRevisionRepository $revisions,
        private readonly EmploymentExitDocumentService $exitDocuments,
    ) {}

    /** @return array<string,mixed> */
    public function generate(
        int $supplierId,
        int $runId,
        int $revisionId,
        ?int $actorUserId,
    ): array {
        if ($supplierId <= 0 || $runId <= 0 || $revisionId <= 0) {
            throw new \InvalidArgumentException(
                'Identita dávky výstupních dokumentů není platná.',
            );
        }
        $revision = $this->documentRepository->approvedRevision(
            $supplierId,
            $runId,
            $revisionId,
        );
        if ($revision === null) {
            throw new \DomainException(
                'Dávku dokumentů lze spustit jen nad schválenou revizí.',
            );
        }
        $periodStart = substr((string) $revision['period_start'], 0, 10);
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')
            ->format('Y-m-d');

        $payslips = $this->payslips->generate(
            $supplierId,
            $runId,
            $revisionId,
            $actorUserId,
        );
        $bundle = $this->documents->generateMonthlyBundle(
            $supplierId,
            $runId,
            $revisionId,
            'payroll-monthly-bundle:' . $runId . ':' . $revisionId,
            $actorUserId,
        );

        $exits = [];
        $missing = [];
        foreach ($this->revisions->endedEmploymentsInPeriod(
            $supplierId,
            $periodStart,
            $periodEnd,
        ) as $employment) {
            $archived = [];
            foreach ($this->documentRepository->listEmploymentExitDocuments(
                $supplierId,
                $employment['id'],
            ) as $document) {
                $kind = (string) $document['document_kind'];
                $archived[$kind] = (int) $document['id'];
            }
            $readiness = $this->exitDocuments->readiness(
                $supplierId,
                $employment['id'],
            );
            $documents = [];
            foreach ([
                PayrollDocumentKind::EmploymentCertificate->value =>
                    'employment_certificate',
                PayrollDocumentKind::AverageEarningsCertificate->value =>
                    'average_earnings_certificate',
                PayrollDocumentKind::AverageEarningsStatement->value =>
                    'average_earnings_statement',
            ] as $kind => $readinessKey) {
                $state = $readiness[$readinessKey];
                $documents[$kind] = [
                    'required' =>
                        $kind === PayrollDocumentKind::EmploymentCertificate->value,
                    'archived' => array_key_exists($kind, $archived),
                    'document_id' => $archived[$kind] ?? null,
                    'available' => $state['available'],
                    'readiness_code' => $state['readiness_code'],
                ];
            }
            $exits[] = [
                'employment_id' => $employment['id'],
                'employee_id' => $employment['employee_id'],
                'employee_name' => $employment['employee_name'],
                'end_date' => $employment['end_date'],
                'relation_type' => $employment['relation_type'],
                'documents' => $documents,
            ];
            $certificate =
                $documents[PayrollDocumentKind::EmploymentCertificate->value];
            if ($certificate['archived'] !== true) {
                $missing[] = 'employment_certificate_missing:'
                    . $employment['id'];
            }
        }

        return [
            'run_id' => $runId,
            'revision_id' => $revisionId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'payslips' => [
                'archived' => count($payslips),
                'document_ids' => array_map(
                    static fn (array $document): int => (int) $document['id'],
                    $payslips,
                ),
            ],
            'monthly_bundle' => [
                'document_id' => (int) $bundle['id'],
            ],
            'employment_exits' => $exits,
            'missing' => $missing,
            'complete' => $missing === [],
        ];
    }
}
