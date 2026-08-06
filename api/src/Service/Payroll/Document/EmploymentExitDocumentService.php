<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentExitRevisionRepository;
use PDO;

final class EmploymentExitDocumentService
{
    private const SAVEPOINT = 'employment_exit_document';

    public function __construct(
        private readonly Connection $db,
        private readonly EmploymentExitSnapshotBuilder $builder,
        private readonly EmploymentCertificatePdfRenderer $renderer,
        private readonly PayrollDocumentService $documents,
        private readonly PayrollEmploymentExitRevisionRepository $revisions,
        private readonly PayrollAverageEarningRepository $averageEarnings,
    ) {}

    /**
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    public function generateEmploymentCertificate(
        int $supplierId,
        int $employmentId,
        array $evidence,
        string $idempotencyKey,
        int $actorUserId,
    ): array {
        $this->validateRequest(
            $supplierId,
            $employmentId,
            $idempotencyKey,
            $actorUserId,
        );
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        $scope = $this->documents->beginStorageScope();
        try {
            $prepared = $this->builder->build(
                $supplierId,
                $employmentId,
                $evidence,
                $actorUserId,
            );
            $revision = $prepared['revision'];
            $artifact = $this->renderer->render($prepared['document']);
            $document = $this->documents->archiveEmploymentExitPdf(
                $supplierId,
                self::positiveInt($revision, 'id'),
                self::positiveInt($revision, 'employee_id'),
                $artifact,
                $idempotencyKey,
                $actorUserId,
                $scope,
            );
            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            $this->documents->commitStorageScope($scope);

            return $document;
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $ownsTransaction);
            try {
                $this->documents->cleanupStorageScope($supplierId, $scope);
            } catch (\Throwable $cleanupException) {
                throw new \RuntimeException(
                    'Výstupní dokument selhal a osiřelé soubory se '
                        . 'nepodařilo uklidit.',
                    previous: $cleanupException,
                );
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function generateAverageEarningsCertificate(
        int $supplierId,
        int $employmentId,
        string $idempotencyKey,
        int $actorUserId,
    ): array {
        $this->validateRequest(
            $supplierId,
            $employmentId,
            $idempotencyKey,
            $actorUserId,
        );
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        try {
            $sources = $this->revisions->lockCertificateSources(
                $supplierId,
                $employmentId,
            );
            EmploymentExitRelationshipPolicy::documentKind(
                self::text($sources['employment'], 'relation_type'),
            );
            $blocker = $this->averageEarningsReadiness(
                $supplierId,
                $employmentId,
                $sources['employment'],
            );
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $ownsTransaction);
            throw $exception;
        }
        $this->finishReadTransaction($pdo, $ownsTransaction);

        throw new EmploymentExitReadinessException(
            self::text($blocker, 'readiness_code'),
            self::averageEarningsMessage($blocker),
        );
    }

    /**
     * @return array{
     *   employment_certificate:array{
     *     available:bool,
     *     readiness_code:?string,
     *     deduction_claim_ids:list<int>
     *   },
     *   average_earnings_certificate:array{
     *     available:bool,
     *     readiness_code:?string,
     *     decisive_year:?int,
     *     decisive_quarter:?int
     *   }
     * }
     */
    public function readiness(int $supplierId, int $employmentId): array
    {
        if ($supplierId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException(
                'Identita pracovního vztahu není platná.',
            );
        }
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        try {
            $sources = $this->revisions->lockCertificateSources(
                $supplierId,
                $employmentId,
            );
            EmploymentExitRelationshipPolicy::documentKind(
                self::text($sources['employment'], 'relation_type'),
            );
            $claims = $this->revisions->lockContinuingDeductionClaims(
                $supplierId,
                self::positiveInt($sources['employee'], 'id'),
                self::text($sources['employment'], 'end_date'),
            );
            $certificate = [
                'available' => true,
                'readiness_code' => null,
                'deduction_claim_ids' => array_map(
                    static fn (array $claim): int =>
                        self::positiveInt($claim, 'id'),
                    $claims,
                ),
            ];
            $average = $this->averageEarningsReadiness(
                $supplierId,
                $employmentId,
                $sources['employment'],
            );
            $this->finishReadTransaction($pdo, $ownsTransaction);
        } catch (EmploymentExitReadinessException $exception) {
            $this->rollback($pdo, $ownsTransaction);
            $certificate = [
                'available' => false,
                'readiness_code' => $exception->readinessCode,
                'deduction_claim_ids' => [],
            ];
            $average = [
                'available' => false,
                'readiness_code' => $exception->readinessCode,
                'decisive_year' => null,
                'decisive_quarter' => null,
            ];
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $ownsTransaction);
            throw $exception;
        }

        return [
            'employment_certificate' => $certificate,
            'average_earnings_certificate' => $average,
        ];
    }

    /**
     * Potvrzení podle § 313 odst. 2 zatím nemá ověřený legislativní přepočet
     * schváleného hrubého hodinového průměru na čistý měsíční výdělek podle
     * zákona o zaměstnanosti, takže zůstává vždy fail-closed. Rozlišuje ale,
     * jestli chybí samotný schválený podklad (MZ-07), nebo jestli podklad
     * existuje a chybí jen ověřené pravidlo přepočtu.
     *
     * @param array<string,mixed> $employment
     * @return array{
     *   available:bool,
     *   readiness_code:string,
     *   decisive_year:int,
     *   decisive_quarter:int
     * }
     */
    private function averageEarningsReadiness(
        int $supplierId,
        int $employmentId,
        array $employment,
    ): array {
        $end = new \DateTimeImmutable(self::text($employment, 'end_date'));
        $decisiveYear = (int) $end->format('Y');
        $decisiveQuarter = intdiv(((int) $end->format('n')) - 1, 3) + 1;
        $snapshot = $this->averageEarnings->findApproved(
            $supplierId,
            $employmentId,
            $decisiveYear,
            $decisiveQuarter,
        );

        return [
            'available' => false,
            'readiness_code' => $snapshot === null
                ? 'average_earnings_snapshot_missing'
                : 'average_earnings_net_conversion_not_verified',
            'decisive_year' => $decisiveYear,
            'decisive_quarter' => $decisiveQuarter,
        ];
    }

    /** @param array<string,mixed> $blocker */
    private static function averageEarningsMessage(array $blocker): string
    {
        $year = self::positiveInt($blocker, 'decisive_year');
        $quarter = self::positiveInt($blocker, 'decisive_quarter');

        return match (self::text($blocker, 'readiness_code')) {
            'average_earnings_snapshot_missing' => sprintf(
                'Pro rozhodné období %d/Q%d chybí schválený snapshot '
                    . 'průměrného výdělku. Nejprve ho založte a schvalte '
                    . 'v modulu Absence a průměry.',
                $year,
                $quarter,
            ),
            'average_earnings_net_conversion_not_verified' => sprintf(
                'Za rozhodné období %d/Q%d existuje schválený hrubý '
                    . 'hodinový průměr, ale ověřený přepočet na čistý '
                    . 'měsíční výdělek podle zákona o zaměstnanosti zatím '
                    . 'v aplikaci není implementován. Potvrzení prosím '
                    . 'vystavte mimo aplikaci.',
                $year,
                $quarter,
            ),
            default => 'Potvrzení podle § 313 odst. 2 zatím nelze vydat.',
        };
    }

    private function validateRequest(
        int $supplierId,
        int $employmentId,
        string $idempotencyKey,
        int $actorUserId,
    ): void {
        if ($supplierId <= 0 || $employmentId <= 0 || $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Identita požadavku na výstupní dokument není platná.',
            );
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 200) {
            throw new \InvalidArgumentException(
                'Idempotency-Key výstupního dokumentu není platný.',
            );
        }
    }

    private function finishReadTransaction(PDO $pdo, bool $owns): void
    {
        if ($owns) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        }
    }

    private function rollback(PDO $pdo, bool $owns): void
    {
        if (!$pdo->inTransaction()) {
            return;
        }
        if ($owns) {
            $pdo->rollBack();
            return;
        }
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
        $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
    }

    /** @param array<string,mixed> $row */
    private static function positiveInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) || $value <= 0) {
            throw new \RuntimeException("Pole {$field} není kladné číslo.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException("Pole {$field} není text.");
        }

        return $value;
    }
}
