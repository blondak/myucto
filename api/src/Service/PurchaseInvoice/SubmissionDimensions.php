<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use PDO;

/**
 * Dimenze zvolené už při nahrání dokladu do příchozích (Účtování podle dimenzí, F5,
 * migrace 1950). Typicky středisko: zaměstnanec nebo klient na portálu nahrává
 * účtenku svého střediska.
 *
 * Při zpracování podání (vytěžení i ruční přepis) se volba propíše do hlavičky
 * vzniklé přijaté faktury ({@see applyToInvoice()}) — jen u typu, který faktura ještě
 * explicitně nemá. Před výchozími hodnotami dodavatele a zakázky má přednost: ty se
 * na doklad neukládají a doplní je až zaúčtování tam, kde doklad hodnotu nemá.
 *
 * Portál nabízí jen aktivní hodnoty aktivních typů druhu Středisko, které firma
 * zobrazuje na dokladech, a z nich jen id, kód, název a nadřízenou hodnotu
 * ({@see portalChoices()}). Klientský uživatel nemá právo na účetnictví, takže celý
 * číselník dimenzí (odpovědné osoby, poznámky, vazby na vozy a zakázky, ostatní
 * typy) mu nepatří; středisko je přitom přesně to, co o účtence ví on, ne účetní.
 */
final class SubmissionDimensions
{
    public const PORTAL_KINDS = ['cost_center'];

    public function __construct(
        private readonly Connection $db,
        private readonly DimensionService $dimensions,
    ) {}

    public function enabled(int $supplierId): bool
    {
        return (new DimensionRepository($this->db))->enabled($supplierId);
    }

    /**
     * @return list<array{id:int, name:string, values:list<array{id:int, code:string, name:string, parent_id:?int}>}>
     */
    public function portalChoices(int $supplierId): array
    {
        if (!$this->enabled($supplierId)) {
            return [];
        }
        $repo = new DimensionRepository($this->db);
        $out = [];
        foreach ($this->allowedTypes($supplierId, true) as $type) {
            $values = [];
            foreach ($repo->listValues($supplierId, (int) $type['id'], false) as $v) {
                $values[] = [
                    'id' => (int) $v['id'],
                    'code' => (string) $v['code'],
                    'name' => (string) $v['name'],
                    'parent_id' => $v['parent_id'] !== null ? (int) $v['parent_id'] : null,
                ];
            }
            if ($values !== []) {
                $out[] = ['id' => (int) $type['id'], 'name' => (string) $type['name'], 'values' => $values];
            }
        }
        return $out;
    }

    /**
     * Ověří volbu z formuláře nahrání. Prázdná hodnota typ vypustí.
     *
     * @param array<int|string,mixed> $raw typ => hodnota
     * @return array<int,int>
     * @throws DimensionException
     */
    public function validate(int $supplierId, array $raw, bool $portal): array
    {
        $raw = array_filter($raw, static fn (mixed $v): bool => $v !== null && $v !== '' && (int) $v > 0);
        if ($raw === []) {
            return [];
        }
        if (!$this->enabled($supplierId)) {
            throw new DimensionException('dimensions_disabled', 'Dimenze nejsou u firmy zapnuté.', 409);
        }
        $norm = $this->dimensions->normalize($supplierId, $raw);
        $allowed = [];
        foreach ($this->allowedTypes($supplierId, $portal) as $type) {
            $allowed[(int) $type['id']] = true;
        }
        foreach (array_keys($norm) as $typeId) {
            if (!isset($allowed[$typeId])) {
                throw new DimensionException('invalid_dimension', 'Tento typ dimenze nelze při nahrání dokladu zvolit.', 400);
            }
        }
        return $norm;
    }

    /** @param array<int,int> $dims typ => hodnota (z {@see validate()}) */
    public function save(int $supplierId, int $submissionId, array $dims): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM purchase_invoice_submission_dimensions WHERE supplier_id = ? AND submission_id = ?')
            ->execute([$supplierId, $submissionId]);
        if ($dims === []) {
            return;
        }
        $insert = $pdo->prepare(
            'INSERT INTO purchase_invoice_submission_dimensions (submission_id, supplier_id, dimension_type_id, dimension_value_id)
             SELECT s.id, s.supplier_id, ?, ? FROM purchase_invoice_submissions s WHERE s.id = ? AND s.supplier_id = ?'
        );
        foreach ($dims as $typeId => $valueId) {
            $insert->execute([$typeId, $valueId, $submissionId, $supplierId]);
        }
    }

    /**
     * @param list<int> $submissionIds
     * @return array<int,array<int,int>> podání => typ => hodnota
     */
    public function forSubmissions(int $supplierId, array $submissionIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $submissionIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT submission_id, dimension_type_id, dimension_value_id
               FROM purchase_invoice_submission_dimensions
              WHERE supplier_id = ? AND submission_id IN ({$marks})
              ORDER BY submission_id, dimension_type_id"
        );
        $stmt->execute([$supplierId, ...$ids]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['submission_id']][(int) $r['dimension_type_id']] = (int) $r['dimension_value_id'];
        }
        return $out;
    }

    /**
     * Doplní řádkům podání klíč `dimensions` (typ => hodnota, JSON objekt).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function attach(int $supplierId, array $rows): array
    {
        $map = $this->forSubmissions($supplierId, array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows));
        return array_map(static fn (array $r): array => $r + ['dimensions' => (object) ($map[(int) ($r['id'] ?? 0)] ?? [])], $rows);
    }

    /**
     * Propíše volbu z podání do hlavičky přijaté faktury: jen typ, který faktura
     * explicitně nemá, a jen hodnotu, která je pořád aktivní. Zaúčtovaný doklad se
     * přerazítkuje ({@see DimensionService::saveDocument()}). Selhání dimenzí
     * zpracování dokladu neshodí — doklad vznikne, dimenzi doplní účetní.
     *
     * @return array<int,int> propsané typy => hodnoty
     */
    public function applyToInvoice(int $supplierId, int $submissionId, int $purchaseInvoiceId): array
    {
        $chosen = $this->forSubmissions($supplierId, [$submissionId])[$submissionId] ?? [];
        if ($chosen === [] || !$this->enabled($supplierId)) {
            return [];
        }
        $values = (new DimensionRepository($this->db))->valuesByIds($supplierId, array_values($chosen));
        $current = (new DimensionAssignmentRepository($this->db))->documentDimensions($supplierId, 'purchase_invoice', $purchaseInvoiceId);
        $header = $current['header'];
        $applied = [];
        foreach ($chosen as $typeId => $valueId) {
            $value = $values[$valueId] ?? null;
            if (isset($header[$typeId]) || $value === null || !$value['is_active'] || $value['type_id'] !== $typeId) {
                continue;
            }
            $header[$typeId] = $valueId;
            $applied[$typeId] = $valueId;
        }
        if ($applied === []) {
            return [];
        }
        try {
            $this->dimensions->saveDocument($supplierId, 'purchase_invoice', $purchaseInvoiceId, $header, null);
        } catch (DimensionException) {
            return [];
        }
        return $applied;
    }

    /**
     * Typy, které jde zvolit při nahrání: aktivní a zobrazované na dokladech; portál
     * navíc jen Středisko.
     *
     * @return list<array<string,mixed>>
     */
    private function allowedTypes(int $supplierId, bool $portal): array
    {
        return array_values(array_filter(
            (new DimensionRepository($this->db))->listTypes($supplierId, false),
            static fn (array $t): bool => (bool) $t['show_on_documents']
                && (!$portal || in_array((string) $t['kind'], self::PORTAL_KINDS, true)),
        ));
    }
}
