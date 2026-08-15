<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Evidence výzev k odstranění vad podání (§ 74 daňového řádu, migrace 1394).
 *
 * Zápis běží přes `row_version` a databázový trigger, který povoluje jen posun
 * o jedničku a odmítá přepis už zaznamenané odpovědi. Souběžná editace tak
 * skončí konfliktem, ne tichým přepsáním doložené skutečnosti.
 */
final class SubmissionDefectNoticeRepository
{
    private const TABLE = 'submission_defect_notices';

    private const COLUMNS = 'id, supplier_id, environment, outbox_id, inbox_message_id, notice_reference,
        authority_kind, defect_ground, consequence, delivered_on, respond_by_on, respond_by_source,
        stated_period_days, respond_by_shifted, status, responded_on, response_outbox_id, outcome,
        note, row_version, created_by, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable(self::TABLE);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data): array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::TABLE . '
                (supplier_id, environment, outbox_id, inbox_message_id, notice_reference, authority_kind,
                 defect_ground, consequence, delivered_on, respond_by_on, respond_by_source,
                 stated_period_days, respond_by_shifted, status, responded_on, response_outbox_id,
                 outcome, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['supplier_id'],
            $data['environment'],
            $data['outbox_id'],
            $data['inbox_message_id'],
            $data['notice_reference'],
            $data['authority_kind'],
            $data['defect_ground'],
            $data['consequence'],
            $data['delivered_on'],
            $data['respond_by_on'],
            $data['respond_by_source'],
            $data['stated_period_days'],
            $data['respond_by_shifted'],
            $data['status'],
            $data['responded_on'],
            $data['response_outbox_id'],
            $data['outcome'],
            $data['note'],
            $data['created_by'],
        ]);

        $row = $this->find((int) $data['supplier_id'], (int) $this->db->pdo()->lastInsertId());
        if ($row === null) {
            throw new \RuntimeException('Výzva se uložila, ale nepodařilo se ji načíst.');
        }

        return $row;
    }

    /**
     * Už tuhle zprávu někdo jako výzvu zaevidoval?
     *
     * @return array<string,mixed>|null
     */
    public function findByInboxMessage(int $supplierId, int $inboxMessageId): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND inbox_message_id = ?'
        );
        $stmt->execute([$supplierId, $inboxMessageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalize($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForSupplier(int $supplierId, string $environment, bool $openOnly = false, int $limit = 200): array
    {
        $this->assertAvailable();
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
                 WHERE supplier_id = ? AND environment = ?';
        if ($openOnly) {
            // „Vyřízené" je jen `answered_in_time` a `withdrawn`. `unknown` mezi ně
            // nepatří — neznalost není vyřízení.
            $sql .= " AND status NOT IN ('answered_in_time', 'withdrawn')";
        }
        $sql .= ' ORDER BY (respond_by_on IS NULL) DESC, respond_by_on ASC, id DESC LIMIT ' . $limit;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $environment]);

        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> */
    public function listForOutbox(int $supplierId, int $outboxId): array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND outbox_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$supplierId, $outboxId]);

        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Optimistický zámek. Vrací `null`, když se řádek mezitím změnil — volající
     * z toho udělá konflikt, ne tichý přepis.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function update(int $supplierId, int $id, int $expectedRowVersion, array $data): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . '
                    SET outbox_id = ?, notice_reference = ?, authority_kind = ?, defect_ground = ?,
                        consequence = ?, delivered_on = ?, respond_by_on = ?, respond_by_source = ?,
                        stated_period_days = ?, respond_by_shifted = ?, status = ?, responded_on = ?,
                        response_outbox_id = ?, outcome = ?, note = ?, row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?'
        );
        $stmt->execute([
            $data['outbox_id'],
            $data['notice_reference'],
            $data['authority_kind'],
            $data['defect_ground'],
            $data['consequence'],
            $data['delivered_on'],
            $data['respond_by_on'],
            $data['respond_by_source'],
            $data['stated_period_days'],
            $data['respond_by_shifted'],
            $data['status'],
            $data['responded_on'],
            $data['response_outbox_id'],
            $data['outcome'],
            $data['note'],
            $supplierId,
            $id,
            $expectedRowVersion,
        ]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->find($supplierId, $id);
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new \DomainException(
                'Evidence výzev k odstranění vad není v databázi k dispozici (chybí migrace 1394).',
            );
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        foreach (['id', 'supplier_id', 'row_version'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['outbox_id', 'inbox_message_id', 'response_outbox_id', 'stated_period_days', 'created_by'] as $key) {
            $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
        }
        $row['respond_by_shifted'] = (bool) $row['respond_by_shifted'];

        return $row;
    }
}
