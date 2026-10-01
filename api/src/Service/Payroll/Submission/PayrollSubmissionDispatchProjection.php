<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use Psr\Log\LoggerInterface;

/** Promítne doložené odeslání a doručení obecné fronty do mzdového podání. */
final readonly class PayrollSubmissionDispatchProjection
{
    public function __construct(
        private PayrollSubmissionRepository $repository,
        private PayrollSubmissionService $submissions,
        private LoggerInterface $logger,
        private PayrollSubmissionSettlementService $settlements,
    ) {}

    public function project(
        int $supplierId,
        string $artifactKind,
        int $artifactId,
        string $externalMessageId,
    ): void {
        if ($artifactKind !== 'payroll_submission') {
            return;
        }

        try {
            $artifact = $this->repository->findArtifact(
                $supplierId,
                $artifactId,
            );
            if ($artifact === null) {
                throw new \DomainException(
                    'Mzdový artefakt odeslaného podání nebyl nalezen.',
                );
            }
            $submission = $this->submissions->get(
                $supplierId,
                (int) $artifact['submission_id'],
            );
            if ($submission['status'] !== 'ready') {
                return;
            }
            $this->submissions->transition(
                $supplierId,
                (int) $submission['id'],
                (int) $submission['row_version'],
                'submitted',
                $externalMessageId,
            );
        } catch (\Throwable $exception) {
            // Odeslání už proběhlo a nesmí se kvůli chybě projekce opakovat.
            // Fronta zůstává závazným důkazem a incident je dohledatelný v logu.
            $this->logger->error(
                'Sent payroll submission could not be projected to payroll state',
                [
                    'supplier_id' => $supplierId,
                    'artifact_id' => $artifactId,
                    'error' => $exception->getMessage(),
                ],
            );
        }
    }

    /**
     * Zpráva prokazatelně dorazila do schránky úřadu (stav `delivered` nebo
     * připojená doručenka). U agend, na které úřad výsledek neposílá, je tím
     * povinnost splněná — rozhoduje
     * {@see PayrollSubmissionSettlementService::settleByDelivery()}.
     *
     * Chyba se jen loguje: doručení už nastalo a jeho zápis se nesmí vrátit
     * kvůli tomu, že se nepovedlo uzavřít povinnost. Ruční „Označit za
     * vyřízené" zůstává jako záchrana.
     *
     * @param array<string,mixed> $outboxRow
     */
    public function projectDelivery(int $supplierId, array $outboxRow): void
    {
        if ((string) ($outboxRow['artifact_kind'] ?? '') !== 'payroll_submission') {
            return;
        }

        try {
            $artifact = $this->repository->findArtifact(
                $supplierId,
                (int) $outboxRow['artifact_id'],
            );
            if ($artifact === null) {
                return;
            }
            $this->settlements->settleByDelivery(
                $supplierId,
                (string) $artifact['environment'],
                (int) $artifact['submission_id'],
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Delivered payroll submission could not settle its obligation',
                [
                    'supplier_id' => $supplierId,
                    'outbox_id' => $outboxRow['id'] ?? null,
                    'error' => $exception->getMessage(),
                ],
            );
        }
    }
}
