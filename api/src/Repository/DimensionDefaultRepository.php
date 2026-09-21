<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Výchozí dimenze klienta a zakázky (`dimension_defaults`, migrace 1861).
 *
 * Mapa typ => hodnota, nejvýš jedna hodnota za typ (unikátní klíč). Validaci hodnot
 * dělá volající přes {@see \MyInvoice\Service\Accounting\Dimension\DimensionService::normalize()};
 * vlastnictví klienta/zakázky firmou hlídá {@see ownsEntity()}.
 */
final class DimensionDefaultRepository
{
    public const ENTITIES = ['client', 'project'];

    public function __construct(private readonly Connection $db) {}

    /**
     * Klient patří firmě přímo, zakázka přes svého klienta (projects nemá supplier_id).
     */
    public function ownsEntity(int $supplierId, string $entity, int $entityId): bool
    {
        $stmt = $this->db->pdo()->prepare($entity === 'project'
            ? 'SELECT 1 FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ? AND c.supplier_id = ?'
            : 'SELECT 1 FROM clients WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$entityId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array<int,int> typ => hodnota */
    public function forEntity(int $supplierId, string $entity, int $entityId): array
    {
        $column = self::column($entity);
        $stmt = $this->db->pdo()->prepare(
            "SELECT dimension_type_id, dimension_value_id FROM dimension_defaults
              WHERE supplier_id = ? AND {$column} = ? ORDER BY dimension_type_id"
        );
        $stmt->execute([$supplierId, $entityId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['dimension_type_id']] = (int) $r['dimension_value_id'];
        }
        return $out;
    }

    /** @param array<int,int> $dims typ => hodnota (už normalizovaná) */
    public function replace(int $supplierId, string $entity, int $entityId, array $dims): void
    {
        $column = self::column($entity);
        $pdo = $this->db->pdo();
        $pdo->prepare("DELETE FROM dimension_defaults WHERE supplier_id = ? AND {$column} = ?")
            ->execute([$supplierId, $entityId]);
        if ($dims === []) {
            return;
        }
        $rows = [];
        $params = [];
        foreach ($dims as $typeId => $valueId) {
            $rows[] = '(?, ?, ?, ?)';
            array_push($params, $supplierId, $entityId, (int) $typeId, (int) $valueId);
        }
        $pdo->prepare(
            "INSERT INTO dimension_defaults (supplier_id, {$column}, dimension_type_id, dimension_value_id) VALUES "
            . implode(',', $rows)
        )->execute($params);
    }

    private static function column(string $entity): string
    {
        return match ($entity) {
            'client' => 'client_id',
            'project' => 'project_id',
            default => throw new \InvalidArgumentException("Neznámá entita výchozích dimenzí: {$entity}"),
        };
    }
}
