<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Volba certifikátu pro mzdová podání. Certifikát sám je v osobním trezoru;
 * tady se drží jen to, který z uložených patří ke které firmě a prostředí.
 */
final class PayrollSigningProfileRepository
{
    private const TABLE = 'payroll_submission_signing_profiles';
    private const COLUMNS = 'supplier_id, environment, credential_id, owner_user_id,
                    cssz_registered_serial, row_version, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable(self::TABLE);
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, string $environment): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND environment = ?',
        );
        $statement->execute([$supplierId, $environment]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::normalize($row) : null;
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
        $this->assertAvailable();
        $existing = $this->find($supplierId, $environment);
        if ($existing === null) {
            if ($expectedVersion !== null) {
                throw new \DomainException('Volba certifikátu pro mzdová podání neexistuje.');
            }
            $insert = $this->db->pdo()->prepare(
                'INSERT INTO ' . self::TABLE . '
                    (supplier_id, environment, credential_id, owner_user_id,
                     cssz_registered_serial, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
            );
            $insert->execute([
                $supplierId,
                $environment,
                $credentialId,
                $ownerUserId,
                $registeredSerial,
                $actorUserId,
            ]);

            return $this->reload($supplierId, $environment);
        }
        // Optimistický zámek se vyhodnocuje v SAMOTNÉM UPDATE, ne porovnáním
        // proti dříve načtenému řádku: mezi čtením a zápisem se dá vklínit
        // druhý požadavek a porovnání v PHP by ho nezachytilo.
        $update = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . '
                SET credential_id = ?, owner_user_id = ?, cssz_registered_serial = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND environment = ?'
                . ($expectedVersion === null ? '' : ' AND row_version = ?'),
        );
        $parameters = [
            $credentialId,
            $ownerUserId,
            $registeredSerial,
            $supplierId,
            $environment,
        ];
        if ($expectedVersion !== null) {
            $parameters[] = $expectedVersion;
        }
        $update->execute($parameters);
        if ($update->rowCount() !== 1) {
            throw new \DomainException('Volba certifikátu byla mezitím změněna.');
        }

        return $this->reload($supplierId, $environment);
    }

    public function delete(int $supplierId, string $environment): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $statement = $this->db->pdo()->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE supplier_id = ? AND environment = ?',
        );
        $statement->execute([$supplierId, $environment]);
    }

    /** @return array<string,mixed> */
    private function reload(int $supplierId, string $environment): array
    {
        $row = $this->find($supplierId, $environment);
        if ($row === null) {
            throw new \DomainException('Volbu certifikátu se nepodařilo načíst zpět.');
        }

        return $row;
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new \DomainException(
                'Tabulka volby podpisového certifikátu chybí; spusťte migrace.',
            );
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        foreach (['supplier_id', 'credential_id', 'owner_user_id', 'row_version'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        return $row;
    }
}
