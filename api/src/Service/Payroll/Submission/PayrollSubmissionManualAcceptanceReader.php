<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionManualAcceptanceRepository;

/**
 * Čtecí tvar ručních potvrzení přijetí — jediný pro dialog, detail i štítky
 * v seznamech, aby „Přijato ručně" všude neslo totéž: kdo, kdy, proč a jestli
 * ho pozdější ověřený protokol nevyvrátil.
 */
final readonly class PayrollSubmissionManualAcceptanceReader
{
    public function __construct(
        private PayrollSubmissionManualAcceptanceRepository $acceptances,
    ) {}

    /**
     * Celá historie podání, od nejnovějšího.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $supplierId, string $environment, int $submissionId): array
    {
        $rows = $this->acceptances->history($supplierId, $environment, $submissionId);
        $contradictions = $this->acceptances->contradictions(
            $supplierId,
            $environment,
            array_map(static fn (array $row): int => $row['id'], $rows),
        );

        return array_map(
            fn (array $row): array => $this->present($row, $contradictions[$row['id']] ?? null),
            array_reverse($rows),
        );
    }

    /**
     * Poslední ruční potvrzení ke každému podání — pro štítek v seznamech.
     *
     * @param list<int> $submissionIds
     * @return array<int,array<string,mixed>> `submission_id` → potvrzení
     */
    public function summaries(int $supplierId, string $environment, array $submissionIds): array
    {
        if ($submissionIds === []) {
            return [];
        }
        $latest = $this->acceptances->latestBySubmissions($supplierId, $environment, $submissionIds);
        if ($latest === []) {
            return [];
        }
        $contradictions = $this->acceptances->contradictions(
            $supplierId,
            $environment,
            array_values(array_map(static fn (array $row): int => $row['id'], $latest)),
        );
        $result = [];
        foreach ($latest as $submissionId => $row) {
            $result[$submissionId] = $this->present($row, $contradictions[$row['id']] ?? null);
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $row
     * @param array{issue_id:int,receipt_id:int,remote_status:?string,received_at:string,is_resolved:bool}|null $contradiction
     * @return array<string,mixed>
     */
    public function present(array $row, ?array $contradiction = null): array
    {
        return [
            'id' => (int) $row['id'],
            'submission_id' => (int) $row['submission_id'],
            'variant' => (string) $row['variant'],
            'status_before' => (string) $row['status_before'],
            'note' => (string) $row['note'],
            'authority_accepted_on' => $row['authority_accepted_on'] === null
                ? null
                : (string) $row['authority_accepted_on'],
            'attachment_artifact_id' => $row['attachment_artifact_id'],
            'recorded_by' => (int) $row['recorded_by'],
            'recorded_by_name' => $row['recorded_by_name'] ?? null,
            'recorded_at' => (string) $row['recorded_at'],
            'contradiction' => $contradiction === null ? null : [
                'receipt_id' => $contradiction['receipt_id'],
                'remote_status' => $contradiction['remote_status'],
                'received_at' => $contradiction['received_at'],
                'is_resolved' => $contradiction['is_resolved'],
            ],
        ];
    }
}
