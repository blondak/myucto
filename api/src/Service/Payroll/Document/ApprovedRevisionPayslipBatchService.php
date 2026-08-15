<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\ApprovedRevisionPayslipRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

/**
 * Výplatní pásky schválené revize — celá dávka, nebo nic.
 *
 * Vykreslení PDF běží UVNITŘ transakce, a je to záměr, ne opomenutí. Dávka je
 * jeden účetní úkon: půlka lidí s páskou a půlka bez ní je horší stav než dávka,
 * která spadla celá, protože pásky jsou neměnné doklady a částečnou sadu už nejde
 * jen tak dogenerovat — každá páska nese otisk zdroje a čas vydání. Proto se
 * revize zamkne `FOR UPDATE` (jeden řádek, ne celý zaměstnavatel), soubory se
 * odkládají do `PayrollDocumentStorageScope` a při chybě je úklid smete spolu
 * s rollbackem. Volající navíc dávku běžně vkládá do vlastní transakce mzdového
 * běhu (odtud SAVEPOINT), takže atomicita je součástí schválení běhu.
 *
 * Cenou je doba transakce úměrná počtu osob. Kdyby to jednou vadilo, správný
 * postup NENÍ transakci rozdělit, ale vykreslit PDF dopředu a dovnitř transakce
 * pustit jen zápisy — s tím, že se pak musí po zamčení revize znovu ověřit otisk
 * zdroje, protože dnes ho `prepareAll()` kontroluje až pod zámkem.
 */
final class ApprovedRevisionPayslipBatchService
{
    private const SAVEPOINT = 'approved_revision_payslip_batch';

    public function __construct(
        private readonly Connection $db,
        private readonly ApprovedRevisionPayslipRepository $sources,
        private readonly PayslipDocumentSnapshotHydrator $hydrator,
        private readonly PayrollDocumentService $documents,
    ) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function generate(
        int $supplierId,
        int $runId,
        int $revisionId,
        ?int $actorUserId,
        ?PayrollDocumentStorageScope $storageScope = null,
    ): array {
        if ($supplierId <= 0 || $runId <= 0 || $revisionId <= 0) {
            throw new \InvalidArgumentException('Identita dávky výplatních pásek není platná.');
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        $ownsStorageScope = $storageScope === null;
        $storageScope ??= $this->beginStorageScope();

        try {
            $source = $this->sources->lockSource($supplierId, $runId, $revisionId);
            if ($source === null) {
                throw new \DomainException(
                    'Výplatní pásky lze vytvořit pouze ze schválené mzdové revize.',
                );
            }
            $prepared = $this->prepareAll($source, $revisionId);
            $result = [];
            foreach ($prepared as $item) {
                $result[] = $this->documents->generatePayslip(
                    $supplierId,
                    $runId,
                    $revisionId,
                    $item['employee_id'],
                    $item['document'],
                    $this->idempotencyKey(
                        $supplierId,
                        $runId,
                        $revisionId,
                        $item['employee_id'],
                        $item['source_hash'],
                    ),
                    $actorUserId,
                    null,
                    $storageScope,
                );
            }
            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            if ($ownsStorageScope) {
                $this->commitStorageScope($storageScope);
            }
            return $result;
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $ownsTransaction);
            if ($ownsStorageScope) {
                try {
                    $this->cleanupStorageScope($supplierId, $storageScope);
                } catch (\Throwable $cleanupException) {
                    throw new \RuntimeException(
                        'Dávka pásek selhala a osiřelé soubory se nepodařilo uklidit.',
                        previous: $cleanupException,
                    );
                }
            }
            throw $exception;
        }
    }

    public function beginStorageScope(): PayrollDocumentStorageScope
    {
        return $this->documents->beginStorageScope();
    }

    public function commitStorageScope(
        PayrollDocumentStorageScope $storageScope,
    ): void {
        $this->documents->commitStorageScope($storageScope);
    }

    public function cleanupStorageScope(
        int $supplierId,
        PayrollDocumentStorageScope $storageScope,
    ): void {
        $this->documents->cleanupStorageScope($supplierId, $storageScope);
    }

    /**
     * @param array{
     *   period_start:string,
     *   result_snapshot_json:string,
     *   result_snapshot_hash:string,
     *   people:list<array<string,mixed>>
     * } $source
     * @return list<array{
     *   employee_id:int,
     *   source_hash:string,
     *   document:PayslipDocumentData
     * }>
     */
    private function prepareAll(array $source, int $revisionId): array
    {
        $revisionJson = $source['result_snapshot_json'];
        $revisionHash = $source['result_snapshot_hash'];
        $this->assertHash($revisionHash, 'schválené revize');
        if (!hash_equals($revisionHash, hash('sha256', $revisionJson))) {
            throw new \DomainException('Otisk výsledku schválené revize nesouhlasí.');
        }
        $revision = json_decode(
            $revisionJson,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (!is_array($revision) || array_is_list($revision)) {
            throw new \DomainException('Výsledek schválené revize není objekt.');
        }
        $rootPeople = $revision['people'] ?? null;
        if (!is_array($rootPeople) || !array_is_list($rootPeople)) {
            throw new \DomainException('Schválená revize nemá seznam výsledků osob.');
        }

        $rootByEmployee = [];
        foreach ($rootPeople as $personValue) {
            $person = $this->object(
                $personValue,
                'Výsledek osoby ve schválené revizi',
            );
            $employeeId = $this->positiveInteger(
                $person['employee_id'] ?? null,
                'employee_id výsledku osoby',
            );
            if (isset($rootByEmployee[$employeeId])) {
                throw new \DomainException('Schválená revize nemá jednoznačné výsledky osob.');
            }
            $rootByEmployee[$employeeId] = $person;
        }
        if (count($rootByEmployee) !== count($source['people'])) {
            throw new \DomainException('Schválená revize nemá výsledek každé zmrazené osoby.');
        }

        $period = substr($source['period_start'], 0, 7);
        $prepared = [];
        foreach ($source['people'] as $stored) {
            $employeeId = $this->positiveInteger(
                $stored['employee_id'] ?? null,
                'employee_id zmrazené osoby',
            );
            $storedJson = $stored['result_json'] ?? null;
            $storedHash = $stored['result_hash'] ?? null;
            if (
                $employeeId <= 0
                || ($stored['status'] ?? null) !== 'calculated'
                || !is_string($storedJson)
                || !is_string($storedHash)
                || !isset($rootByEmployee[$employeeId])
            ) {
                throw new \DomainException(
                    "Zmrazená osoba {$employeeId} nemá úplný vypočtený výsledek.",
                );
            }
            $this->assertHash($storedHash, "výsledku osoby {$employeeId}");
            if (
                !hash_equals($storedHash, hash('sha256', $storedJson))
                || !hash_equals(
                    $storedHash,
                    hash('sha256', CanonicalJson::encode($rootByEmployee[$employeeId])),
                )
            ) {
                throw new \DomainException(
                    "Otisk výsledku osoby {$employeeId} nesouhlasí se schválenou revizí.",
                );
            }
            $payslipSnapshot = $this->object(
                $rootByEmployee[$employeeId]['payslip_document'] ?? null,
                "Výsledek osoby {$employeeId} nemá snapshot výplatní pásky",
            );
            $prepared[] = [
                'employee_id' => $employeeId,
                'source_hash' => $storedHash,
                'document' => $this->hydrator->hydrate(
                    $payslipSnapshot,
                    'revision-' . $revisionId,
                    $storedHash,
                    $period,
                ),
            ];
        }
        if ($prepared === []) {
            throw new \DomainException('Schválená revize neobsahuje žádnou vypočtenou osobu.');
        }
        return $prepared;
    }

    private function idempotencyKey(
        int $supplierId,
        int $runId,
        int $revisionId,
        int $employeeId,
        string $sourceHash,
    ): string {
        return 'approved-payslip:' . hash('sha256', implode("\0", [
            (string) $supplierId,
            (string) $runId,
            (string) $revisionId,
            (string) $employeeId,
            $sourceHash,
            PayslipPdfRenderer::VERSION,
        ]));
    }

    private function assertHash(string $hash, string $context): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new \DomainException("Otisk {$context} není platný SHA-256.");
        }
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $context): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("{$context}.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \DomainException("{$context} má neplatný klíč.");
            }
            $result[$key] = $item;
        }
        return $result;
    }

    private function positiveInteger(mixed $value, string $context): int
    {
        if (
            is_int($value)
            && $value > 0
        ) {
            return $value;
        }
        if (
            is_string($value)
            && preg_match('/^[1-9][0-9]*$/D', $value) === 1
            && (int) $value > 0
        ) {
            return (int) $value;
        }
        throw new \DomainException("{$context} není kladné celé číslo.");
    }

    private function rollback(PDO $pdo, bool $ownsTransaction): void
    {
        if (!$pdo->inTransaction()) {
            return;
        }
        if ($ownsTransaction) {
            $pdo->rollBack();
            return;
        }
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
        $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
    }
}
