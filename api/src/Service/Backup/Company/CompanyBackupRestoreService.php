<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;

/** Otevře čerstvou ověřenou archive session a předá ji atomickému commitu. */
final readonly class CompanyBackupRestoreService
{
    public function __construct(
        private CompanyBackupRestoreCoordinator $coordinator,
        private CompanyBackupArchiveLimits $limits =
            new CompanyBackupArchiveLimits(),
    ) {}

    public function restore(
        string $archivePath,
        #[\SensitiveParameter] string $password,
        CompanyBackupTechnicalValidation $validation,
        CompanyBackupDataPreflightResult $preflight,
        CompanyBackupReferenceDecisionPlan $decisions,
        PayrollSensitiveData $sensitiveData,
    ): CompanyBackupRestoreResult {
        $source = null;
        $result = null;
        $failure = null;
        try {
            $source = new CompanyBackupImportArchiveSource(
                $archivePath,
                $password,
                $validation,
                $this->limits,
            );
            $result = $this->coordinator->restore(
                $source,
                $validation->inspection->manifest->backupId,
                $preflight,
                $decisions,
                $sensitiveData,
            );
        } catch (\Throwable $e) {
            $failure = $e;
        }

        if ($source instanceof CompanyBackupImportArchiveSource) {
            try {
                // Koordinátor session běžně zavře před publikací. Opakované
                // zavření je no-op; tato větev vlastní i časné precondition chyby.
                $source->close();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }
        if ($failure instanceof \Throwable) {
            throw $failure;
        }
        if (!$result instanceof CompanyBackupRestoreResult) {
            throw new \LogicException('Obnova archivu nevytvořila výsledek.');
        }
        return $result;
    }
}
