<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeConsentWindow;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeSegment;
use PDO;

/**
 * Podklady pro hlídání limitů § 93: evidovaný přesčas z docházky a evidované
 * souhlasy zaměstnanců.
 *
 * Zdrojem přesčasu je `payroll_time_entries` s kategorií `overtime` — tedy
 * přesně evidence, kterou zaměstnavateli ukládá vést § 96 odst. 1 písm. a)
 * bod 2 zákoníku práce. Peněžní stránka (`payroll_inputs` / `PREMIE_PRIPLATKY`)
 * se schválně nečte: limit je o odpracovaném čase, ne o vyplacené částce, a
 * kontrola tak nemůže nijak zasáhnout do výplaty.
 *
 * Revize: `status <> 'superseded'` stejně jako
 * {@see PayrollTimeRepository::entries()} — nahrazená revize záznamu by se
 * jinak počítala dvakrát a přepsaný přesčas by nafoukl roční součet.
 */
final class PayrollOvertimeRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Přesčas podle MÍSTNÍHO data začátku intervalu.
     *
     * Do SQL jde širší UTC okno (±2 dny), protože `starts_at_utc` a místní datum
     * se můžou lišit o hodiny; zúžení na požadovaný rozsah proběhne až v PHP nad
     * `timezone_name` řádku — tak to dělá i zbytek modulu docházky.
     *
     * @param list<int> $employmentIds
     * @return array<int,list<OvertimeSegment>>
     */
    public function segmentsForMany(
        int $supplierId,
        array $employmentIds,
        string $fromDate,
        string $toDate,
    ): array {
        $result = array_fill_keys($employmentIds, []);
        if ($employmentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT employment_id, starts_at_utc, ends_at_utc,
                    timezone_name, break_minutes
               FROM payroll_time_entries
              WHERE supplier_id = ?
                AND employment_id IN ({$placeholders})
                AND category = 'overtime'
                AND status <> 'superseded'
                AND starts_at_utc >= ?
                AND starts_at_utc < ?
              ORDER BY starts_at_utc, id"
        );
        $stmt->execute([
            $supplierId,
            ...$employmentIds,
            (new \DateTimeImmutable($fromDate . ' 00:00:00', new \DateTimeZone('UTC')))
                ->modify('-2 days')->format('Y-m-d H:i:s'),
            (new \DateTimeImmutable($toDate . ' 00:00:00', new \DateTimeZone('UTC')))
                ->modify('+3 days')->format('Y-m-d H:i:s'),
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $timezone = new \DateTimeZone((string) $row['timezone_name']);
            $start = new \DateTimeImmutable(
                (string) $row['starts_at_utc'],
                new \DateTimeZone('UTC'),
            );
            $end = new \DateTimeImmutable(
                (string) $row['ends_at_utc'],
                new \DateTimeZone('UTC'),
            );
            $date = $start->setTimezone($timezone)->format('Y-m-d');
            if ($date < $fromDate || $date > $toDate) {
                continue;
            }
            $minutes = max(0, intdiv($end->getTimestamp() - $start->getTimestamp(), 60)
                - (int) $row['break_minutes']);
            if ($minutes === 0) {
                continue;
            }
            $result[(int) $row['employment_id']][] = new OvertimeSegment($date, $minutes);
        }

        return $result;
    }

    /**
     * @param list<int> $employmentIds
     * @return array<int,list<OvertimeConsentWindow>>
     */
    public function consentsForMany(int $supplierId, array $employmentIds): array
    {
        $result = array_fill_keys($employmentIds, []);
        if ($employmentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, employment_id, valid_from, valid_to
               FROM payroll_overtime_consents
              WHERE supplier_id = ?
                AND employment_id IN ({$placeholders})
              ORDER BY valid_from, id"
        );
        $stmt->execute([$supplierId, ...$employmentIds]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employment_id']][] = new OvertimeConsentWindow(
                (string) $row['valid_from'],
                $row['valid_to'] === null ? null : (string) $row['valid_to'],
                (int) $row['id'],
            );
        }

        return $result;
    }

    /**
     * Souhlasy v podobě, ve které je čte obrazovka docházky — na rozdíl od
     * {@see self::consentsForMany()} nesou i doklad, poznámku a `row_version`.
     *
     * @param list<int> $employmentIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function consentRowsForMany(int $supplierId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $result = array_fill_keys($employmentIds, []);
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, employment_id, valid_from, valid_to,
                    document_reference, note, row_version, created_at
               FROM payroll_overtime_consents
              WHERE supplier_id = ?
                AND employment_id IN ({$placeholders})
              ORDER BY valid_from, id"
        );
        $stmt->execute([$supplierId, ...$employmentIds]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id'] = (int) $row['id'];
            $row['employment_id'] = (int) $row['employment_id'];
            $row['row_version'] = (int) $row['row_version'];
            $result[$row['employment_id']][] = $row;
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function consents(int $supplierId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employment_id, valid_from, valid_to,
                    document_reference, note, row_version, created_at
               FROM payroll_overtime_consents
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY valid_from, id'
        );
        $stmt->execute([$supplierId, $employmentId]);

        return array_values(array_map(
            static function (array $row): array {
                $row['id'] = (int) $row['id'];
                $row['employment_id'] = (int) $row['employment_id'];
                $row['row_version'] = (int) $row['row_version'];

                return $row;
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return array<string,mixed> */
    public function saveConsent(
        int $supplierId,
        int $employmentId,
        ?int $consentId,
        string $validFrom,
        ?string $validTo,
        ?string $documentReference,
        ?string $note,
        int $expectedVersion,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        if ($consentId === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_overtime_consents
                    (supplier_id, employment_id, valid_from, valid_to,
                     document_reference, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $employmentId,
                $validFrom,
                $validTo,
                $documentReference,
                $note,
                $userId,
            ]);
            $consentId = (int) $pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare(
                'UPDATE payroll_overtime_consents
                    SET valid_from = ?, valid_to = ?, document_reference = ?,
                        note = ?, row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND employment_id = ?
                    AND row_version = ?'
            );
            $stmt->execute([
                $validFrom,
                $validTo,
                $documentReference,
                $note,
                $supplierId,
                $consentId,
                $employmentId,
                $expectedVersion,
            ]);
            if ($stmt->rowCount() === 0) {
                throw new \DomainException(
                    'Souhlas s prací přesčas mezitím změnil někdo jiný, načtěte ho znovu.',
                );
            }
        }

        $loaded = $pdo->prepare(
            'SELECT id, employment_id, valid_from, valid_to,
                    document_reference, note, row_version, created_at
               FROM payroll_overtime_consents
              WHERE supplier_id = ? AND id = ?'
        );
        $loaded->execute([$supplierId, $consentId]);
        $row = $loaded->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Uložený souhlas s prací přesčas se nepodařilo načíst.');
        }
        $row['id'] = (int) $row['id'];
        $row['employment_id'] = (int) $row['employment_id'];
        $row['row_version'] = (int) $row['row_version'];

        return $row;
    }
}
