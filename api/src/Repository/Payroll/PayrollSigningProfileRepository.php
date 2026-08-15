<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Volba certifikátu pro mzdová podání. Certifikát sám je v osobním trezoru;
 * tady se drží jen to, který z uložených patří ke které firmě a prostředí.
 */
final class PayrollSigningProfileRepository
{
    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable('payroll_submission_signing_profiles');
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, string $environment): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT supplier_id, environment, credential_id, owner_user_id,
                    cssz_registered_serial, row_version, created_at, updated_at
               FROM payroll_submission_signing_profiles
              WHERE supplier_id = ? AND environment = ?',
            [$supplierId, $environment],
        );

        return $row === false ? null : $row;
    }

    /**
     * Uloží volbu. Prostředí ani firma se nikdy nemění — jen certifikát a jeho
     * registrace u ČSSZ, a to s optimistickým zámkem, aby dva souběžné pokusy
     * nezvolily každý jiný certifikát.
     *
     * @return array<string,mixed>
     */
    public function save(
        int $supplierId,
        string $environment,
        int $credentialId,
        int $ownerUserId,
        ?string $registeredSerial,
        ?int $expectedVersion,
        ?int $actorUserId,
    ): array {
        $existing = $this->find($supplierId, $environment);
        if ($existing === null) {
            if ($expectedVersion !== null) {
                throw new \DomainException('Volba certifikátu pro mzdová podání neexistuje.');
            }
            $this->db->execute(
                'INSERT INTO payroll_submission_signing_profiles
                    (supplier_id, environment, credential_id, owner_user_id,
                     cssz_registered_serial, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $supplierId,
                    $environment,
                    $credentialId,
                    $ownerUserId,
                    $registeredSerial,
                    $actorUserId,
                ],
            );

            return (array) $this->find($supplierId, $environment);
        }
        if ($expectedVersion !== null && (int) $existing['row_version'] !== $expectedVersion) {
            throw new \DomainException('Volba certifikátu byla mezitím změněna.');
        }
        $this->db->execute(
            'UPDATE payroll_submission_signing_profiles
                SET credential_id = ?, owner_user_id = ?, cssz_registered_serial = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND environment = ?',
            [
                $credentialId,
                $ownerUserId,
                $registeredSerial,
                $supplierId,
                $environment,
            ],
        );

        return (array) $this->find($supplierId, $environment);
    }

    public function delete(int $supplierId, string $environment): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $this->db->execute(
            'DELETE FROM payroll_submission_signing_profiles
              WHERE supplier_id = ? AND environment = ?',
            [$supplierId, $environment],
        );
    }
}
