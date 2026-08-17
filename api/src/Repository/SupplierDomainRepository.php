<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class SupplierDomainRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly EntityCache $cache,
    ) {}

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM supplier_domains WHERE supplier_id = ? ORDER BY is_primary DESC, hostname'
        );
        $stmt->execute([$supplierId]);
        return array_map(self::hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function findOwned(int $supplierId, int $domainId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM supplier_domains WHERE supplier_id = ? AND id = ? LIMIT 1';
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $domainId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    /** @return array<string,mixed>|null */
    public function findByHostname(string $hostname, bool $includeInactive = true): ?array
    {
        $key = 'domain:host:' . $hostname . ':' . (int) $includeInactive;
        return $this->cache->remember(
            EntityCache::GROUP_SUPPLIER,
            $key,
            function () use ($hostname, $includeInactive): ?array {
                $sql = 'SELECT * FROM supplier_domains WHERE hostname = ?';
                if (!$includeInactive) $sql .= " AND status = 'active'";
                $sql .= ' LIMIT 1';
                $stmt = $this->db->pdo()->prepare($sql);
                $stmt->execute([$hostname]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return $row === false ? null : self::hydrate($row);
            },
        );
    }

    /** @return array<string,mixed>|null */
    public function primaryForSupplier(int $supplierId, string $purpose): ?array
    {
        if (!in_array($purpose, ['portal', 'public_links'], true)) {
            throw new \InvalidArgumentException('Neplatný účel domény.');
        }
        $key = 'domain:primary:' . $supplierId . ':' . $purpose;
        return $this->cache->remember(
            EntityCache::GROUP_SUPPLIER,
            $key,
            function () use ($supplierId, $purpose): ?array {
                $stmt = $this->db->pdo()->prepare(
                    "SELECT * FROM supplier_domains
                      WHERE supplier_id = ? AND status = 'active'
                        AND purpose IN (?, 'all')
                   ORDER BY CASE WHEN ? = 'portal' THEN is_primary_portal ELSE is_primary_public END DESC,
                            id ASC
                      LIMIT 1"
                );
                $stmt->execute([$supplierId, $purpose, $purpose]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return $row === false ? null : self::hydrate($row);
            },
        );
    }

    /** @return array<string,mixed> */
    public function create(int $supplierId, string $hostname, string $purpose, int $userId): array
    {
        self::assertPurpose($purpose);
        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO supplier_domains
                (supplier_id, hostname, purpose, verification_token, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$supplierId, $hostname, $purpose, $token, $userId ?: null, $userId ?: null]);
        return $this->findOwned($supplierId, (int) $this->db->pdo()->lastInsertId())
            ?? throw new \RuntimeException('Vytvořenou doménu se nepodařilo načíst.');
    }

    /** @return array<string,mixed> */
    public function update(int $supplierId, int $domainId, string $purpose, bool $primary, int $userId): array
    {
        self::assertPurpose($purpose);
        $stmt = $this->db->pdo()->prepare(
            "UPDATE supplier_domains
                SET purpose = ?,
                    is_primary_portal = CASE WHEN ? = 1 AND ? IN ('portal','all') THEN 1 ELSE 0 END,
                    is_primary_public = CASE WHEN ? = 1 AND ? IN ('public_links','all') THEN 1 ELSE 0 END,
                    updated_by = ?
              WHERE supplier_id = ? AND id = ? AND status <> 'active'"
        );
        $stmt->execute([
            $purpose,
            (int) $primary,
            $purpose,
            (int) $primary,
            $purpose,
            $userId ?: null,
            $supplierId,
            $domainId,
        ]);
        if ($stmt->rowCount() < 1) {
            $current = $this->findOwned($supplierId, $domainId);
            if ($current === null) {
                throw new \OutOfBoundsException('Doména neexistuje.');
            }
            if ($current['status'] === 'active') {
                throw new \DomainException('Účel aktivní domény nelze měnit; nejdřív ji deaktivuj.');
            }
        }
        return $this->findOwned($supplierId, $domainId)
            ?? throw new \OutOfBoundsException('Doména neexistuje.');
    }

    /** @return array<string,mixed> */
    public function rotateChallenge(int $supplierId, int $domainId, int $userId): array
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->pdo()->prepare(
            "UPDATE supplier_domains
                SET verification_token = ?, status = 'pending', verified_at = NULL,
                    last_checked_at = NULL, verification_error = NULL, updated_by = ?
              WHERE supplier_id = ? AND id = ? AND status <> 'active'"
        );
        $stmt->execute([$token, $userId ?: null, $supplierId, $domainId]);
        if ($stmt->rowCount() < 1) {
            if ($this->findOwned($supplierId, $domainId) === null) {
                throw new \OutOfBoundsException('Doména neexistuje.');
            }
            throw new \DomainException('Aktivní doméně nelze měnit challenge.');
        }
        return $this->findOwned($supplierId, $domainId)
            ?? throw new \OutOfBoundsException('Doména neexistuje.');
    }

    public function recordVerification(
        int $supplierId,
        int $domainId,
        bool $verified,
        ?string $error,
        int $userId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE supplier_domains
                SET status = ?, verified_at = CASE WHEN ? = 1 THEN UTC_TIMESTAMP(6) ELSE NULL END,
                    last_checked_at = UTC_TIMESTAMP(6), verification_error = ?, updated_by = ?
              WHERE supplier_id = ? AND id = ? AND status <> 'active'"
        );
        $stmt->execute([
            $verified ? 'verified' : 'verification_failed',
            (int) $verified,
            $verified ? null : mb_substr((string) $error, 0, 500),
            $userId ?: null,
            $supplierId,
            $domainId,
        ]);
        if ($stmt->rowCount() < 1) throw new \DomainException('Doménu nelze ověřit.');
    }

    /** @return array<string,mixed> */
    public function activate(int $supplierId, int $domainId, bool $primary, int $userId): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $domain = $this->findOwned($supplierId, $domainId, true);
            if ($domain === null) throw new \OutOfBoundsException('Doména neexistuje.');
            if (!in_array($domain['status'], ['verified', 'active'], true) || $domain['verified_at'] === null) {
                throw new \DomainException('Doména musí mít úspěšně ověřené DNS a HTTPS.');
            }
            $portalPrimary = $primary && in_array($domain['purpose'], ['portal', 'all'], true);
            $publicPrimary = $primary && in_array($domain['purpose'], ['public_links', 'all'], true);
            if ($portalPrimary) {
                $pdo->prepare(
                    'UPDATE supplier_domains SET is_primary_portal = 0, updated_by = ?
                      WHERE supplier_id = ? AND id <> ? AND status = \'active\'
                        AND is_primary_portal = 1'
                )->execute([$userId ?: null, $supplierId, $domainId]);
            }
            if ($publicPrimary) {
                $pdo->prepare(
                    'UPDATE supplier_domains SET is_primary_public = 0, updated_by = ?
                      WHERE supplier_id = ? AND id <> ? AND status = \'active\'
                        AND is_primary_public = 1'
                )->execute([$userId ?: null, $supplierId, $domainId]);
            }
            $pdo->prepare(
                "UPDATE supplier_domains
                    SET status = 'active', is_primary_portal = ?, is_primary_public = ?,
                        verification_error = NULL, updated_by = ?
                  WHERE supplier_id = ? AND id = ?"
            )->execute([
                (int) $portalPrimary,
                (int) $publicPrimary,
                $userId ?: null,
                $supplierId,
                $domainId,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->findOwned($supplierId, $domainId)
            ?? throw new \RuntimeException('Aktivovanou doménu se nepodařilo načíst.');
    }

    public function disable(int $supplierId, int $domainId, int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE supplier_domains
                SET status = 'disabled', is_primary_portal = 0,
                    is_primary_public = 0, updated_by = ?
              WHERE supplier_id = ? AND id = ?"
        );
        $stmt->execute([$userId ?: null, $supplierId, $domainId]);
        if ($stmt->rowCount() < 1 && $this->findOwned($supplierId, $domainId) === null) {
            throw new \OutOfBoundsException('Doména neexistuje.');
        }
    }

    public function delete(int $supplierId, int $domainId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "DELETE FROM supplier_domains WHERE supplier_id = ? AND id = ? AND status <> 'active'"
        );
        $stmt->execute([$supplierId, $domainId]);
        if ($stmt->rowCount() < 1) throw new \DomainException('Aktivní nebo neexistující doménu nelze smazat.');
    }

    private static function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, ['portal', 'public_links', 'all'], true)) {
            throw new \InvalidArgumentException('Neplatný účel domény.');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrate(array $row): array
    {
        foreach (['id', 'supplier_id'] as $key) $row[$key] = (int) $row[$key];
        foreach (['is_primary', 'is_primary_portal', 'is_primary_public'] as $key) {
            $row[$key] = (bool) $row[$key];
        }
        foreach (['primary_portal_supplier_id', 'primary_public_supplier_id'] as $key) unset($row[$key]);
        return $row;
    }
}
