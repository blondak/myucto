<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class AnnualPayrollSheetService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollSheetSnapshotBuilder $snapshots,
        private readonly PayrollSheetPdfRenderer $renderer,
        private readonly PayrollDocumentService $documents,
    ) {}

    /** @return array<string,mixed> */
    public function generate(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        ?int $actorUserId,
    ): array {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException(
                'Generování ročního mzdového listu musí vlastnit databázovou transakci.',
            );
        }
        $scope = $this->documents->beginStorageScope();
        $pdo->beginTransaction();
        try {
            $prepared = $this->snapshots->build(
                $supplierId,
                $employeeId,
                $taxYear,
                $actorUserId,
            );
            $artifact = $this->renderer->render($prepared['document']);
            $document = $this->documents->archiveAnnualPdf(
                $supplierId,
                (int) $prepared['revision']['id'],
                $employeeId,
                $artifact,
                'annual-payroll-sheet:' . hash('sha256', implode("\0", [
                    (string) $supplierId,
                    (string) $employeeId,
                    (string) $taxYear,
                    $artifact->sourceSnapshotHash,
                    $artifact->rendererVersion,
                ])),
                $actorUserId,
                $scope,
            );
            $pdo->commit();
            $this->documents->commitStorageScope($scope);
            return $document;
        } catch (\Throwable $exception) {
            $this->rollbackIfOpen($pdo);
            try {
                $this->documents->cleanupStorageScope($supplierId, $scope);
            } catch (\Throwable $cleanupException) {
                throw new \RuntimeException(
                    'Mzdový list selhal a osiřelý soubor se nepodařilo uklidit.',
                    previous: $cleanupException,
                );
            }
            throw $exception;
        }
    }

    private function rollbackIfOpen(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
