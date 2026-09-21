<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\CostCenterRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\PostingService;
use PDO;

/**
 * Firma → Dimenze: číselník typů a stromů hodnot, dimenze dokladů a řádků deníku.
 *
 * Středisko je dimenze navázaná na číselník `cost_centers`: nová hodnota typu
 * Středisko si středisko se stejným kódem najde nebo založí, uzavření hodnoty
 * středisko deaktivuje. Textový `cost_center` na řádcích (mzdy, ruční zápisy) tak
 * zůstává platný a sestavy po středisku ho započítají ({@see DimensionFilter}).
 */
final class DimensionService
{
    /** Výchozí typy: kód => [název, druh, globální je-li firma ve skupině]. */
    public const DEFAULT_TYPES = [
        'stredisko' => ['Středisko', 'cost_center', false],
        'projekt' => ['Projekt', 'project', true],
        'vozidlo' => ['Vozidlo', 'vehicle', false],
        'lokalita' => ['Lokalita', 'location', true],
        'obchodni_pripad' => ['Obchodní případ', 'deal', false],
    ];

    /** Typ dokladu => [tabulka, zdroj zápisu v deníku]. */
    private const DOCUMENTS = [
        'purchase_invoice' => ['purchase_invoices', 'purchase_invoice'],
        'invoice' => ['invoices', 'invoice'],
        'cash_document' => ['cash_documents', 'cash'],
        'bank_transaction' => [null, 'bank'],
        'journal_template' => ['journal_entry_templates', null],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly DimensionRepository $repo,
        private readonly DimensionAssignmentRepository $assignments,
        private readonly CostCenterRepository $costCenters,
        private readonly PostingService $posting,
    ) {}

    /** @return array<string,mixed> */
    public function overview(int $supplierId): array
    {
        $groupId = $this->repo->groupIdOf($supplierId);
        $group = $groupId !== null ? $this->repo->findGroup($groupId) : null;
        return [
            'enabled' => $this->repo->enabled($supplierId),
            'group' => $group === null ? null : $group + ['members' => $this->repo->groupMembers($groupId)],
            'types' => $this->repo->listTypes($supplierId),
            'values' => $this->repo->listValues($supplierId),
        ];
    }

    public function enabled(int $supplierId): bool
    {
        return $this->repo->enabled($supplierId);
    }

    /** Úklid dimenzí smazaného dokladu (vazba je polymorfní, bez FK na doklad). */
    public function forgetDocument(int $supplierId, string $docType, int $docId): void
    {
        $this->assignments->deleteDocument($supplierId, $docType, $docId);
    }

    public function setEnabled(int $supplierId, bool $enabled): void
    {
        $this->repo->setEnabled($supplierId, $enabled);
    }

    /**
     * Založí výchozí typy, které firma (nebo její skupina) ještě nemá. Projekt
     * a Lokalita jsou u firmy ve skupině globální, jinak firemní.
     *
     * @param list<string>|null $only kódy výchozích typů (null = všechny)
     * @return array<string,int> druh => id typu
     */
    public function ensureDefaultTypes(int $supplierId, ?array $only = null): array
    {
        $inGroup = $this->repo->groupIdOf($supplierId) !== null;
        $out = [];
        $order = 0;
        foreach (self::DEFAULT_TYPES as $code => [$name, $kind, $globalInGroup]) {
            $order += 10;
            if ($only !== null && !in_array($code, $only, true)) {
                continue;
            }
            $global = $globalInGroup && $inGroup;
            $existing = $this->repo->findTypeByKind($supplierId, $kind, $global);
            if ($existing === null && !$global) {
                // Firma bez skupiny může mít projekt i lokalitu jako firemní typ.
                $existing = $this->repo->findTypeByKind($supplierId, $kind, false);
            }
            $out[$kind] = $existing !== null
                ? (int) $existing['id']
                : $this->repo->createType($supplierId, $global, [
                    'code' => $code, 'name' => $name, 'kind' => $kind, 'sort_order' => $order,
                ]);
        }
        return $out;
    }

    // ── typy ─────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $body */
    public function createType(int $supplierId, array $body): array
    {
        $code = strtolower(trim((string) ($body['code'] ?? '')));
        $name = trim((string) ($body['name'] ?? ''));
        $kind = (string) ($body['kind'] ?? 'custom');
        $global = ($body['level'] ?? 'company') === 'global';
        if (preg_match('/^[a-z0-9_-]{1,30}$/', $code) !== 1) {
            throw new DimensionException('validation_failed', 'Kód typu: povolené znaky a-z, 0-9, _ a - (nejvýš 30).');
        }
        if ($name === '' || mb_strlen($name) > 100) {
            throw new DimensionException('validation_failed', 'Název typu musí mít 1–100 znaků.');
        }
        if (!in_array($kind, DimensionRepository::KINDS, true)) {
            throw new DimensionException('validation_failed', 'Neznámý druh dimenze.');
        }
        if ($global && $this->repo->groupIdOf($supplierId) === null) {
            throw new DimensionException('no_supplier_group', 'Globální typ vyžaduje, aby firma patřila do skupiny firem.', 409);
        }
        foreach ($this->repo->listTypes($supplierId) as $t) {
            if ($t['code'] === $code && ($t['level'] === 'global') === $global) {
                throw new DimensionException('duplicate_code', "Typ s kódem '{$code}' už existuje.", 409);
            }
        }
        $id = $this->repo->createType($supplierId, $global, [
            'code' => $code,
            'name' => $name,
            'kind' => $kind,
            'show_on_documents' => (bool) ($body['show_on_documents'] ?? true),
            'sort_order' => (int) ($body['sort_order'] ?? 100),
        ]);
        return (array) $this->repo->findType($supplierId, $id);
    }

    /** @param array<string,mixed> $body */
    public function updateType(int $supplierId, int $typeId, array $body): array
    {
        $this->requireType($supplierId, $typeId);
        $changes = array_intersect_key($body, array_flip(['name', 'is_active', 'show_on_documents', 'sort_order']));
        if (array_key_exists('name', $changes)) {
            $changes['name'] = trim((string) $changes['name']);
            if ($changes['name'] === '' || mb_strlen($changes['name']) > 100) {
                throw new DimensionException('validation_failed', 'Název typu musí mít 1–100 znaků.');
            }
        }
        $this->repo->updateType($supplierId, $typeId, $changes);
        return (array) $this->repo->findType($supplierId, $typeId);
    }

    /** @return array{deleted:bool} */
    public function deleteType(int $supplierId, int $typeId): array
    {
        $this->requireType($supplierId, $typeId);
        if ($this->repo->typeInUse($typeId)) {
            $this->repo->updateType($supplierId, $typeId, ['is_active' => false]);
            return ['deleted' => false];
        }
        return ['deleted' => $this->repo->deleteType($supplierId, $typeId)];
    }

    // ── hodnoty ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $body */
    public function createValue(int $supplierId, int $typeId, array $body): array
    {
        $type = $this->requireType($supplierId, $typeId);
        $code = trim((string) ($body['code'] ?? ''));
        if ($code === '' || mb_strlen($code) > 50 || preg_match('/[\x00-\x1F\x7F]/u', $code) === 1) {
            throw new DimensionException('validation_failed', 'Kód hodnoty musí mít 1–50 znaků bez řídicích znaků.');
        }
        if ($this->repo->findValueByCode($typeId, $code) !== null) {
            throw new DimensionException('duplicate_code', "Hodnota s kódem '{$code}' už v typu existuje.", 409);
        }
        $data = $this->valueData($supplierId, $type, $body, null) + ['code' => $code];
        if (!isset($data['name'])) {
            throw new DimensionException('validation_failed', 'Název hodnoty je povinný.');
        }
        if ($type['kind'] === 'cost_center' && $type['level'] === 'company' && !array_key_exists('cost_center_id', $body)) {
            $data['cost_center_id'] = $this->costCenterFor($supplierId, $code, (string) $data['name']);
        }
        $id = $this->repo->createValue($type, $data);
        return (array) $this->repo->findValue($supplierId, $id);
    }

    /** @param array<string,mixed> $body */
    public function updateValue(int $supplierId, int $valueId, array $body): array
    {
        $value = $this->requireValue($supplierId, $valueId);
        $type = $this->requireType($supplierId, $value['type_id']);
        $changes = $this->valueData($supplierId, $type, $body, $value);
        $this->repo->updateValue($supplierId, $valueId, $changes);
        if (array_key_exists('is_active', $changes) && $value['cost_center_id'] !== null && $value['supplier_id'] !== null) {
            $this->costCenters->update($supplierId, $value['cost_center_id'], ['is_active' => (bool) $changes['is_active']]);
        }
        return (array) $this->repo->findValue($supplierId, $valueId);
    }

    /** @return array{deleted:bool} */
    public function deleteValue(int $supplierId, int $valueId): array
    {
        $this->requireValue($supplierId, $valueId);
        if ($this->repo->valueInUse($valueId)) {
            $this->repo->updateValue($supplierId, $valueId, ['is_active' => false]);
            return ['deleted' => false];
        }
        return ['deleted' => $this->repo->deleteValue($supplierId, $valueId)];
    }

    /**
     * Uživatelé firmy, které lze vybrat jako odpovědnou osobu hodnoty.
     *
     * @return list<array{id:int,name:string}>
     */
    public function responsibleCandidates(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT u.id, COALESCE(NULLIF(u.name, ''), u.email) AS name
               FROM users u
               JOIN user_suppliers us ON us.user_id = u.id AND us.supplier_id = ?
              WHERE u.is_active = 1
              ORDER BY name"
        );
        $stmt->execute([$supplierId]);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── přiřazení ────────────────────────────────────────────────────────────

    /**
     * Ověří mapu typ => hodnota (nebo seznam id hodnot) proti číselníku firmy.
     * Prázdná hodnota typ z mapy vypustí. Neaktivní hodnotu odmítne jen u nové
     * volby — uzavřený projekt na starém dokladu zůstat smí.
     *
     * @param array<int|string,mixed> $raw
     * @param array<int,int> $current dosavadní hodnoty (pro povolení uzavřených)
     * @return array<int,int> typ => hodnota
     */
    public function normalize(int $supplierId, array $raw, array $current = []): array
    {
        $pairs = [];
        foreach ($raw as $key => $valueId) {
            if ($valueId === null || $valueId === '' || (int) $valueId <= 0) {
                continue;
            }
            $pairs[] = [array_is_list($raw) ? null : (int) $key, (int) $valueId];
        }
        $values = $this->repo->valuesByIds($supplierId, array_column($pairs, 1));
        $types = [];
        foreach ($this->repo->listTypes($supplierId) as $t) {
            $types[$t['id']] = $t;
        }
        $out = [];
        foreach ($pairs as [$typeId, $valueId]) {
            $value = $values[$valueId] ?? null;
            if ($value === null || ($typeId !== null && $value['type_id'] !== $typeId) || !isset($types[$value['type_id']])) {
                throw new DimensionException('invalid_dimension', 'Neplatná hodnota dimenze #' . $valueId . '.', 400);
            }
            if (!$value['is_active'] && !in_array($valueId, $current, true)) {
                throw new DimensionException('dimension_closed', 'Hodnota dimenze „' . $value['name'] . '" je uzavřená.', 422);
            }
            if (isset($out[$value['type_id']])) {
                throw new DimensionException('invalid_dimension', 'Za jeden typ dimenze lze vybrat jen jednu hodnotu.', 400);
            }
            $out[$value['type_id']] = $valueId;
        }
        ksort($out);
        return $out;
    }

    /** @return array{header:array<int,int>, items:array<int,array<int,int>>} */
    public function documentDimensions(int $supplierId, string $docType, int $docId): array
    {
        $this->requireDocument($supplierId, $docType, $docId);
        return $this->assignments->documentDimensions($supplierId, $docType, $docId);
    }

    /**
     * Uloží dimenze dokladu (hlavička + položky podle pořadí od 1) a promítne je do
     * už zaúčtovaných řádků dokladu.
     *
     * @param array<int|string,mixed> $header
     * @param array<int|string,mixed> $items pořadí položky => mapa typ => hodnota
     * @return array{header:array<int,int>, items:array<int,array<int,int>>, restamp:array{lines:int,needs_repost:bool}}
     */
    public function saveDocument(int $supplierId, string $docType, int $docId, array $header, array $items): array
    {
        $this->requireEnabled($supplierId);
        $this->requireDocument($supplierId, $docType, $docId);
        $current = $this->assignments->documentDimensions($supplierId, $docType, $docId);
        $currentIds = array_values($current['header']);
        foreach ($current['items'] as $dims) {
            array_push($currentIds, ...array_values($dims));
        }
        $normHeader = $this->normalize($supplierId, $header, $currentIds);
        $normItems = [];
        foreach ($items as $itemNo => $dims) {
            if ((int) $itemNo <= 0 || !is_array($dims)) {
                continue;
            }
            $norm = $this->normalize($supplierId, $dims, $currentIds);
            if ($norm !== []) {
                $normItems[(int) $itemNo] = $norm;
            }
        }
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $this->assignments->replaceDocumentDimensions($supplierId, $docType, $docId, $normHeader, $normItems);
            $sourceType = self::DOCUMENTS[$docType][1];
            $restamp = $sourceType !== null
                ? $this->posting->restampDimensions($supplierId, $sourceType, $docId)
                : ['lines' => 0, 'needs_repost' => false];
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['header' => $normHeader, 'items' => $normItems, 'restamp' => $restamp];
    }

    /** @return array<int,array<int,int>> řádek => typ => hodnota */
    public function entryLineDimensions(int $supplierId, int $entryId): array
    {
        return $this->assignments->entryLineDimensions($supplierId, $entryId);
    }

    /**
     * Ruční změna dimenzí řádků zápisu (i zaúčtovaného a v uzavřeném období — mění
     * se jen analytika).
     *
     * @param array<int|string,mixed> $lines id řádku => mapa typ => hodnota
     * @return int počet změněných řádků
     */
    public function saveEntryLines(int $supplierId, int $entryId, array $lines): int
    {
        $this->requireEnabled($supplierId);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ?');
        $stmt->execute([$supplierId, $entryId]);
        $lineIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($lineIds === []) {
            throw new DimensionException('not_found', 'Účetní zápis nenalezen.', 404);
        }
        $current = $this->assignments->entryLineDimensions($supplierId, $entryId);
        $changed = 0;
        foreach ($lines as $lineId => $dims) {
            $lineId = (int) $lineId;
            if (!in_array($lineId, $lineIds, true) || !is_array($dims)) {
                throw new DimensionException('invalid_line', 'Řádek #' . $lineId . ' do zápisu nepatří.', 400);
            }
            $norm = $this->normalize($supplierId, $dims, array_values($current[$lineId] ?? []));
            if ($this->assignments->replaceLineDimensions($supplierId, $lineId, $norm)) {
                $changed++;
            }
        }
        return $changed;
    }

    /**
     * Filtr sestav na hodnotu dimenze včetně podřízených hodnot.
     */
    public function filter(int $supplierId, int $valueId, bool $withDescendants = true): DimensionFilter
    {
        $value = $this->requireValue($supplierId, $valueId);
        $ids = $withDescendants ? $this->repo->descendantIds($supplierId, $valueId) : [$valueId];
        return new DimensionFilter(
            $value['type_id'],
            $valueId,
            $ids,
            array_values($this->repo->costCenterCodes($supplierId, $ids)),
        );
    }

    // ── skupina firem ────────────────────────────────────────────────────────

    /**
     * @param list<int> $accessibleSupplierIds firmy, do kterých má uživatel přístup
     * @return array<string,mixed>
     */
    public function groupInfo(int $supplierId, array $accessibleSupplierIds): array
    {
        $groupId = $this->repo->groupIdOf($supplierId);
        $group = $groupId !== null ? $this->repo->findGroup($groupId) : null;
        return [
            'group' => $group === null ? null : $group + ['members' => $this->repo->groupMembers($groupId)],
            'candidates' => array_values(array_filter(
                $this->repo->groupsOfSuppliers($accessibleSupplierIds),
                static fn (array $g): bool => $g['id'] !== $groupId,
            )),
        ];
    }

    public function createGroup(int $supplierId, string $name): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 190) {
            throw new DimensionException('validation_failed', 'Název skupiny musí mít 1–190 znaků.');
        }
        if ($this->repo->groupIdOf($supplierId) !== null) {
            throw new DimensionException('already_in_group', 'Firma už do skupiny patří.', 409);
        }
        $id = $this->repo->createGroup($name);
        $this->repo->setSupplierGroup($supplierId, $id);
        return $id;
    }

    /**
     * Připojí firmu k existující skupině. Smí jen ten, kdo má přístup aspoň k jedné
     * firmě té skupiny — jinak by se cizí firma připojila ke globálním dimenzím
     * skupiny, kterou nevidí.
     *
     * @param list<int> $accessibleSupplierIds
     */
    public function joinGroup(int $supplierId, int $groupId, array $accessibleSupplierIds, bool $superadmin): void
    {
        if ($this->repo->findGroup($groupId) === null) {
            throw new DimensionException('not_found', 'Skupina nenalezena.', 404);
        }
        $members = array_column($this->repo->groupMembers($groupId), 'id');
        if (!$superadmin && array_intersect($members, $accessibleSupplierIds) === []) {
            throw new DimensionException('not_found', 'Skupina nenalezena.', 404);
        }
        $this->repo->setSupplierGroup($supplierId, $groupId);
    }

    public function leaveGroup(int $supplierId): void
    {
        $this->repo->setSupplierGroup($supplierId, null);
    }

    public function renameGroup(int $supplierId, string $name): void
    {
        $groupId = $this->repo->groupIdOf($supplierId);
        $name = trim($name);
        if ($groupId === null) {
            throw new DimensionException('no_supplier_group', 'Firma nepatří do skupiny firem.', 409);
        }
        if ($name === '' || mb_strlen($name) > 190) {
            throw new DimensionException('validation_failed', 'Název skupiny musí mít 1–190 znaků.');
        }
        $this->repo->renameGroup($groupId, $name);
    }

    // ── interní ──────────────────────────────────────────────────────────────

    public function requireType(int $supplierId, int $typeId): array
    {
        $type = $this->repo->findType($supplierId, $typeId);
        if ($type === null) {
            throw new DimensionException('not_found', 'Typ dimenze nenalezen.', 404);
        }
        return $type;
    }

    public function requireValue(int $supplierId, int $valueId): array
    {
        $value = $this->repo->findValue($supplierId, $valueId);
        if ($value === null) {
            throw new DimensionException('not_found', 'Hodnota dimenze nenalezena.', 404);
        }
        return $value;
    }

    private function requireEnabled(int $supplierId): void
    {
        if (!$this->repo->enabled($supplierId)) {
            throw new DimensionException('dimensions_disabled', 'Dimenze nejsou u firmy zapnuté (Nastavení firmy).', 409);
        }
    }

    public function requireDocument(int $supplierId, string $docType, int $docId): void
    {
        if (!isset(self::DOCUMENTS[$docType])) {
            throw new DimensionException('not_found', 'Neznámý typ dokladu.', 404);
        }
        $table = self::DOCUMENTS[$docType][0];
        if ($table === null) {
            // Výpis nemusí mít supplier_id vyplněné (starší import, avízo) a firmě
            // patří přes číslo účtu. Vlastnictví proto rozhoduje týž resolver jako
            // detail výpisu, jinak by se dimenze pohybu nedaly načíst ani uložit.
            $stmt = $this->db->pdo()->prepare(
                'SELECT 1 FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
                  WHERE bt.id = ? AND ' . BankStatementOwnershipResolver::sql()
            );
            $stmt->execute([$docId, ...BankStatementOwnershipResolver::params($supplierId)]);
        } else {
            $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE id = ? AND supplier_id = ?");
            $stmt->execute([$docId, $supplierId]);
        }
        if ($stmt->fetchColumn() === false) {
            throw new DimensionException('not_found', 'Doklad nenalezen.', 404);
        }
    }

    /**
     * Zvaliduje a připraví pole hodnoty (create i update).
     *
     * @param array<string,mixed> $type
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $current
     * @return array<string,mixed>
     */
    private function valueData(int $supplierId, array $type, array $body, ?array $current): array
    {
        $data = [];
        if (array_key_exists('name', $body) || $current === null) {
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 190) {
                throw new DimensionException('validation_failed', 'Název hodnoty musí mít 1–190 znaků.');
            }
            $data['name'] = $name;
        }
        if (array_key_exists('is_active', $body)) {
            $data['is_active'] = (bool) $body['is_active'];
        }
        if (array_key_exists('parent_id', $body)) {
            $parentId = $body['parent_id'] !== null && (int) $body['parent_id'] > 0 ? (int) $body['parent_id'] : null;
            if ($parentId !== null) {
                $parent = $this->repo->findValue($supplierId, $parentId);
                if ($parent === null || $parent['type_id'] !== (int) $type['id']) {
                    throw new DimensionException('invalid_parent', 'Nadřízená hodnota musí patřit ke stejnému typu.', 400);
                }
                if ($current !== null && in_array($parentId, $this->repo->descendantIds($supplierId, (int) $current['id']), true)) {
                    throw new DimensionException('invalid_parent', 'Hodnotu nelze přesunout pod sebe ani pod svou podřízenou.', 400);
                }
            }
            $data['parent_id'] = $parentId;
        }
        foreach (['responsible_note' => 190, 'note' => 500] as $field => $max) {
            if (array_key_exists($field, $body)) {
                $text = trim((string) ($body[$field] ?? ''));
                if (mb_strlen($text) > $max) {
                    throw new DimensionException('validation_failed', "Pole {$field} je delší než {$max} znaků.");
                }
                $data[$field] = $text === '' ? null : $text;
            }
        }
        if (array_key_exists('sort_order', $body)) {
            $data['sort_order'] = (int) $body['sort_order'];
        }
        if (array_key_exists('responsible_user_id', $body)) {
            $userId = (int) ($body['responsible_user_id'] ?? 0);
            if ($userId > 0 && !in_array($userId, array_column($this->responsibleCandidates($supplierId), 'id'), true)) {
                throw new DimensionException('invalid_reference', 'Odpovědná osoba musí být uživatelem firmy.', 400);
            }
            $data['responsible_user_id'] = $userId > 0 ? $userId : null;
        }
        // Vazby na vůz, zakázku a středisko jsou firemní záznamy — globální hodnota
        // (sdílená skupinou) je nést nemůže.
        $links = [
            'car_id' => 'SELECT 1 FROM cars WHERE id = ? AND supplier_id = ?',
            'project_id' => 'SELECT 1 FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ? AND c.supplier_id = ?',
            'cost_center_id' => 'SELECT 1 FROM cost_centers WHERE id = ? AND supplier_id = ?',
        ];
        foreach ($links as $field => $sql) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $id = (int) ($body[$field] ?? 0);
            if ($id > 0) {
                if ($type['level'] === 'global') {
                    throw new DimensionException('invalid_reference', 'Globální hodnota nemůže odkazovat na záznam jedné firmy.', 400);
                }
                $stmt = $this->db->pdo()->prepare($sql);
                $stmt->execute([$id, $supplierId]);
                if ($stmt->fetchColumn() === false) {
                    throw new DimensionException('invalid_reference', 'Odkazovaný záznam nepatří firmě.', 400);
                }
            }
            $data[$field] = $id > 0 ? $id : null;
        }
        return $data;
    }

    private function costCenterFor(int $supplierId, string $code, string $name): int
    {
        $existing = $this->costCenters->findByCode($supplierId, $code);
        return $existing !== null ? (int) $existing['id'] : $this->costCenters->create($supplierId, $code, $name);
    }
}
