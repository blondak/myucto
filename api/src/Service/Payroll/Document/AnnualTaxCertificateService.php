<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class AnnualTaxCertificateService
{
    public function __construct(
        private readonly Connection $db,
        private readonly AnnualTaxCertificateSnapshotBuilder $snapshots,
        private readonly AnnualTaxCertificatePdfRenderer $renderer,
        private readonly PayrollDocumentService $documents,
    ) {}

    /**
     * @param null|callable(array<string,mixed>):void $beforeCommit
     * @return array<string,mixed>
     */
    public function generate(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        PayrollDocumentKind $kind,
        ?int $actorUserId,
        ?callable $beforeCommit = null,
        ?int $supersedesDocumentId = null,
        ?string $correctionReason = null,
    ): array {
        AnnualTaxCertificateFormCatalog::resolve($taxYear, $kind);
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException(
                'Generování ročního daňového potvrzení musí vlastnit '
                . 'databázovou transakci.',
            );
        }
        $storageLock = 'payroll-document-storage:supplier:' . $supplierId;
        $this->acquireStorageLock($pdo, $storageLock);
        try {
            $scope = $this->documents->beginStorageScope();
            $pdo->beginTransaction();
            try {
                $prepared = $this->snapshots->build(
                    $supplierId,
                    $employeeId,
                    $taxYear,
                    $kind,
                    $actorUserId,
                    $supersedesDocumentId,
                    $correctionReason,
                );
                if (isset($prepared['archived_document'])) {
                    $document = $prepared['archived_document'];
                    if ($beforeCommit !== null) {
                        $beforeCommit($document);
                    }
                    $pdo->commit();
                    $this->documents->commitStorageScope($scope);

                    return $document;
                }
                $artifact = $this->renderer->render($prepared['document']);
                $annualRevisionId = $this->annualRevisionId(
                    $prepared['revision'],
                );
                $document = $this->documents->archiveAnnualPdf(
                    $supplierId,
                    $annualRevisionId,
                    $employeeId,
                    $artifact,
                    'annual-tax-certificate:' . hash('sha256', implode("\0", [
                        (string) $supplierId,
                        (string) $employeeId,
                        (string) $taxYear,
                        $kind->value,
                        $artifact->sourceSnapshotHash,
                        $artifact->rendererVersion,
                    ])),
                    $actorUserId,
                    $scope,
                    $prepared['document']->issuedAt,
                );
                if (($document['created_at'] ?? null)
                    !== $prepared['document']->issuedAt
                ) {
                    throw new \RuntimeException(
                        'Archivní datum vydání neodpovídá zmrazenému snapshotu.',
                    );
                }
                if ($beforeCommit !== null) {
                    $beforeCommit($document);
                }
                $pdo->commit();
                $this->documents->commitStorageScope($scope);

                return $document;
            } catch (\Throwable $cleanupException) {
                $this->rollbackIfOpen($pdo);
                try {
                    $this->documents->cleanupStorageScope(
                        $supplierId,
                        $scope,
                    );
                } catch (\Throwable $storageException) {
                    throw new \RuntimeException(
                        'Daňové potvrzení selhalo a osiřelý soubor se '
                        . 'nepodařilo uklidit.',
                        previous: $storageException,
                    );
                }
                throw $cleanupException;
            }
        } finally {
            $this->releaseStorageLock($pdo, $storageLock);
        }
    }

    private function rollbackIfOpen(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    private function acquireStorageLock(PDO $pdo, string $name): void
    {
        $statement = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $statement->execute([$name]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new \RuntimeException(
                'Úložiště mzdových dokumentů je právě používáno jinou operací.',
            );
        }
    }

    private function releaseStorageLock(PDO $pdo, string $name): void
    {
        $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$name]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new \RuntimeException(
                'Zámek úložiště mzdových dokumentů se nepodařilo uvolnit.',
            );
        }
    }

    /** @param array<string,mixed> $revision */
    private function annualRevisionId(array $revision): int
    {
        $value = $revision['id'] ?? null;
        if ((!is_int($value) && !is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT) === false
            || (int) $value <= 0
        ) {
            throw new \UnexpectedValueException(
                'Roční daňový snapshot nemá platné ID.',
            );
        }

        return (int) $value;
    }
}
