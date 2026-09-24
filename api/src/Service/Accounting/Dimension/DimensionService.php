<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\CostCenterRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionDefaultRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\DocumentRepostService;
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
        private readonly DimensionDefaultRepository $defaults,
        private readonly DimensionDefaults $defaultsResolver,
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

    /**
     * Ověří rozpad řádku nebo dokladu mezi víc hodnot typu. Vstup je typ => seznam
     * `{value_id, share}` (nebo typ => hodnota => podíl); podíl 0–1, aspoň dvě hodnoty,
     * součet 1 (tolerance miliontiny kvůli převodu částky na podíl). Prázdný typ se vypustí.
     *
     * @param array<int|string,mixed> $raw
     * @param list<int> $current dosavadní hodnoty (smí zůstat i uzavřené)
     * @return array<int,array<int,float>> typ => hodnota => podíl
     */
    public function normalizeSplits(int $supplierId, array $raw, array $current = []): array
    {
        $types = [];
        foreach ($this->repo->listTypes($supplierId) as $t) {
            $types[$t['id']] = $t;
        }
        $out = [];
        foreach ($raw as $typeId => $entries) {
            $typeId = (int) $typeId;
            if (!is_array($entries) || $entries === []) {
                continue;
            }
            if (!isset($types[$typeId])) {
                throw new DimensionException('invalid_dimension', 'Neplatný typ dimenze #' . $typeId . ' v rozpadu.', 400);
            }
            $shares = [];
            foreach ($entries as $key => $entry) {
                [$valueId, $share] = is_array($entry)
                    ? [(int) ($entry['value_id'] ?? 0), (float) ($entry['share'] ?? 0)]
                    : [(int) $key, (float) $entry];
                if ($valueId <= 0) {
                    continue;
                }
                if (isset($shares[$valueId])) {
                    throw new DimensionException('invalid_split', 'Hodnota se v rozpadu opakuje.', 400);
                }
                if ($share <= 0 || $share > 1) {
                    throw new DimensionException('invalid_split', 'Podíl v rozpadu musí být větší než 0 % a nejvýš 100 %.', 422);
                }
                $shares[$valueId] = round($share, 10);
            }
            if ($shares === []) {
                continue;
            }
            $values = $this->repo->valuesByIds($supplierId, array_keys($shares));
            foreach (array_keys($shares) as $valueId) {
                $value = $values[$valueId] ?? null;
                if ($value === null || $value['type_id'] !== $typeId) {
                    throw new DimensionException('invalid_dimension', 'Neplatná hodnota dimenze #' . $valueId . ' v rozpadu.', 400);
                }
                if (!$value['is_active'] && !in_array($valueId, $current, true)) {
                    throw new DimensionException('dimension_closed', 'Hodnota dimenze „' . $value['name'] . '" je uzavřená.', 422);
                }
            }
            if (count($shares) < 2) {
                throw new DimensionException('invalid_split', 'Rozpad typu „' . $types[$typeId]['name'] . '" potřebuje aspoň dvě hodnoty.', 422);
            }
            if (abs(array_sum($shares) - 1.0) > 0.000001) {
                throw new DimensionException(
                    'invalid_split',
                    'Rozpad typu „' . $types[$typeId]['name'] . '" musí dát dohromady 100 % (je '
                        . number_format(array_sum($shares) * 100, 2, ',', ' ') . ' %).',
                    422,
                );
            }
            ksort($shares);
            $out[$typeId] = $shares;
        }
        ksort($out);
        return $out;
    }

    /**
     * Rozpad pro API: typ => seznam `{value_id, share}` (JSON objekt podle typu).
     *
     * @param array<int,array<int,float>> $splits typ => hodnota => podíl
     * @return array<int,list<array{value_id:int, share:float}>>
     */
    public static function splitsForApi(array $splits): array
    {
        $out = [];
        foreach ($splits as $typeId => $shares) {
            foreach ($shares as $valueId => $share) {
                $out[(int) $typeId][] = ['value_id' => (int) $valueId, 'share' => (float) $share];
            }
        }
        return $out;
    }

    /**
     * `splits` = pořadí položky (0 = hlavička) => typ => seznam `{value_id, share}`.
     *
     * @return array{header:array<int,int>, items:array<int,array<int,int>>, splits:array<int,array<int,list<array{value_id:int, share:float}>>>}
     */
    public function documentDimensions(int $supplierId, string $docType, int $docId): array
    {
        $this->requireDocument($supplierId, $docType, $docId);
        return $this->assignments->documentDimensions($supplierId, $docType, $docId)
            + ['splits' => array_map([self::class, 'splitsForApi'], $this->assignments->documentSplits($supplierId, $docType, $docId))];
    }

    /**
     * Uloží dimenze dokladu (hlavička + položky podle pořadí od 1) a promítne je do
     * už zaúčtovaných řádků dokladu.
     *
     * Přerazítkování mění jen analytiku (účet, strana, částka ani datum řádku se
     * nemění), proto jde i u zápisu v uzavřeném nebo zamčeném období a nevzniká
     * protizápis. Jedinou výjimkou je rozdělení řádku mezi položky s různými
     * dimenzemi: to mění částky řádků, a smí tedy jen přeúčtování. U zápisu, který
     * se nedá přepsat na místě ({@see DocumentRepostService::decide()}), se takové
     * uložení ODMÍTNE — řádek s hlavičkovými dimenzemi by sestavy po dimenzích tiše
     * zkreslil a přeúčtování, které by to spravilo, tam vede přes storno.
     * `$forRepost` = volá přeúčtování, které řádky hned potom zapíše znovu a rozdělí.
     *
     * `$splits` = rozpad hlavičky (0) a položek (pořadí od 1) mezi víc hodnot typu,
     * pořadí => typ => seznam `{value_id, share}`; null = ponechat dosavadní. Typ
     * s rozpadem nemá jedinou hodnotu a naopak — novější volba vyhrává.
     *
     * @param array<int|string,mixed> $header
     * @param array<int|string,mixed>|null $items pořadí položky => mapa typ => hodnota; null = ponechat
     * @param array<int|string,mixed>|null $splits
     * @return array{header:array<int,int>, items:array<int,array<int,int>>, splits:array<int,array<int,list<array{value_id:int, share:float}>>>, restamp:array{lines:int,needs_repost:bool,locked:bool}}
     */
    public function saveDocument(int $supplierId, string $docType, int $docId, array $header, ?array $items, bool $forRepost = false, ?array $splits = null): array
    {
        $this->requireEnabled($supplierId);
        $this->requireDocument($supplierId, $docType, $docId);
        return $this->atomically(function () use ($supplierId, $docType, $docId, $header, $items, $forRepost, $splits): array {
            $result = $this->applyDocument($supplierId, $docType, $docId, $header, $items, $splits);
            if (!$forRepost && $result['restamp']['needs_repost'] && $result['restamp']['locked']) {
                throw new DimensionException('split_in_locked_period', self::SPLIT_LOCKED_MESSAGE, 409);
            }
            return $result;
        });
    }

    public const SPLIT_LOCKED_MESSAGE = 'Položky dokladu mají různé dimenze, ale zaúčtovaný řádek je jen jeden a zápis '
        . 'leží v uzavřeném nebo zamčeném období — rozdělit ho podle položek by změnilo částky řádků, a to jde jen '
        . 'přeúčtováním. Dejte položkám stejnou dimenzi (nebo ji zadejte jen v hlavičce dokladu), případně upravte '
        . 'dimenze přímo na řádcích zápisu v účetním deníku.';

    /**
     * Náhled uložení dimenzí dokladu: co by po uložení neslo každý řádek jeho živých
     * zápisů. Počítá se TOUTÉŽ cestou jako {@see saveDocument()} (zápis + rollback),
     * takže se náhled s výsledkem nemůže rozejít.
     *
     * @param array<int|string,mixed> $header
     * @param array<int|string,mixed>|null $items
     * @param array<int|string,mixed>|null $splits
     * @return array{header:array<int,int>, items:array<int,array<int,int>>,
     *               restamp:array{lines:int,needs_repost:bool,locked:bool}, refused:bool,
     *               lines:list<array{id:int, entry_id:int, account_code:?string, account_name:?string, side:string, amount:float, dimensions:array<int,int>}>}
     */
    public function previewDocument(int $supplierId, string $docType, int $docId, array $header, ?array $items, ?array $splits = null): array
    {
        $this->requireEnabled($supplierId);
        $this->requireDocument($supplierId, $docType, $docId);
        return $this->atomically(function () use ($supplierId, $docType, $docId, $header, $items, $splits): array {
            $result = $this->applyDocument($supplierId, $docType, $docId, $header, $items, $splits);
            $result['refused'] = $result['restamp']['needs_repost'] && $result['restamp']['locked'];
            $result['lines'] = $this->postedLines($supplierId, self::DOCUMENTS[$docType][1], $docId);
            return $result;
        }, true);
    }

    /**
     * @param array<int|string,mixed> $header
     * @param array<int|string,mixed>|null $items
     * @param array<int|string,mixed>|null $splits
     * @return array{header:array<int,int>, items:array<int,array<int,int>>, splits:array<int,array<int,list<array{value_id:int, share:float}>>>, restamp:array{lines:int,needs_repost:bool,locked:bool}}
     */
    private function applyDocument(int $supplierId, string $docType, int $docId, array $header, ?array $items, ?array $splits = null): array
    {
        $current = $this->assignments->documentDimensions($supplierId, $docType, $docId);
        $currentSplits = $this->assignments->documentSplits($supplierId, $docType, $docId);
        $currentIds = array_values($current['header']);
        foreach ($current['items'] as $dims) {
            array_push($currentIds, ...array_values($dims));
        }
        foreach ($currentSplits as $byType) {
            foreach ($byType as $shares) {
                array_push($currentIds, ...array_keys($shares));
            }
        }
        $normHeader = $this->normalize($supplierId, $header, $currentIds);
        $normItems = $items === null ? $current['items'] : [];
        foreach ($items ?? [] as $itemNo => $dims) {
            if ((int) $itemNo <= 0 || !is_array($dims)) {
                continue;
            }
            $norm = $this->normalize($supplierId, $dims, $currentIds);
            if ($norm !== []) {
                $normItems[(int) $itemNo] = $norm;
            }
        }
        if ($splits === null) {
            // Rozpad se ponechá, jen typ, kterému teď volba dala jedinou hodnotu, ho ztratí.
            $normSplits = $currentSplits;
            foreach ($normSplits as $itemNo => $byType) {
                $single = $itemNo === 0 ? $normHeader : ($normItems[$itemNo] ?? []);
                $normSplits[$itemNo] = array_diff_key($byType, $single);
            }
        } else {
            $normSplits = [];
            foreach ($splits as $itemNo => $byType) {
                if ((int) $itemNo < 0 || !is_array($byType)) {
                    continue;
                }
                $norm = $this->normalizeSplits($supplierId, $byType, $currentIds);
                if ($norm !== []) {
                    $normSplits[(int) $itemNo] = $norm;
                }
            }
            foreach ($normSplits as $itemNo => $byType) {
                if ($itemNo === 0) {
                    $normHeader = array_diff_key($normHeader, $byType);
                } elseif (isset($normItems[$itemNo])) {
                    $normItems[$itemNo] = array_diff_key($normItems[$itemNo], $byType);
                }
            }
        }
        $normSplits = array_filter($normSplits, static fn (array $byType): bool => $byType !== []);
        ksort($normSplits);
        $this->assignments->replaceDocumentDimensions($supplierId, $docType, $docId, $normHeader, $normItems);
        if ($normSplits !== [] || $currentSplits !== []) {
            $this->assignments->replaceDocumentSplits($supplierId, $docType, $docId, $normSplits);
        }
        $sourceType = self::DOCUMENTS[$docType][1];
        $restamp = $sourceType !== null
            ? $this->posting->restampDimensions($supplierId, $sourceType, $docId)
            : ['lines' => 0, 'needs_repost' => false];
        $restamp['locked'] = $sourceType !== null && $this->postedOutsideOpenPeriod($supplierId, $sourceType, $docId);
        if ($sourceType === 'invoice' || $sourceType === 'purchase_invoice') {
            $restamp['lines'] += $this->restampPayments($supplierId, $sourceType, $docId);
        }
        return [
            'header' => $normHeader,
            'items' => $normItems,
            'splits' => array_map([self::class, 'splitsForApi'], $normSplits),
            'restamp' => $restamp,
        ];
    }

    /**
     * Úhrady (bankovní pohyby, pokladní doklady) přebírají dimenze placené faktury
     * při zaúčtování. Změna dimenzí faktury se proto promítne i do jejich zápisů,
     * jinak by saldo po dimenzi zůstalo rozjeté.
     */
    private function restampPayments(int $supplierId, string $sourceType, int $docId): int
    {
        $pdo = $this->db->pdo();
        if ($sourceType === 'invoice') {
            $bank = $pdo->prepare(
                'SELECT ip.bank_transaction_id FROM invoice_payments ip
                   JOIN invoices i ON i.id = ip.invoice_id AND i.supplier_id = ?
                  WHERE ip.invoice_id = ? AND ip.bank_transaction_id IS NOT NULL
                 UNION
                 SELECT bank_transaction_id FROM payment_matches WHERE supplier_id = ? AND invoice_id = ?
                 UNION
                 SELECT bt.id FROM bank_transactions bt
                   JOIN bank_statements bs ON bs.id = bt.statement_id
                  WHERE bt.matched_invoice_id = ? AND ' . BankStatementOwnershipResolver::sql()
            );
            $bank->execute([$supplierId, $docId, $supplierId, $docId, $docId, ...BankStatementOwnershipResolver::params($supplierId)]);
            $cashColumn = 'invoice_id';
        } else {
            $bank = $pdo->prepare('SELECT DISTINCT bank_transaction_id FROM payment_matches WHERE supplier_id = ? AND purchase_invoice_id = ?');
            $bank->execute([$supplierId, $docId]);
            $cashColumn = 'purchase_invoice_id';
        }
        $cash = $pdo->prepare("SELECT id FROM cash_documents WHERE supplier_id = ? AND {$cashColumn} = ?");
        $cash->execute([$supplierId, $docId]);
        $lines = 0;
        foreach ($bank->fetchAll(PDO::FETCH_COLUMN) as $txId) {
            $lines += $this->posting->restampDimensions($supplierId, 'bank', (int) $txId)['lines'];
        }
        foreach ($cash->fetchAll(PDO::FETCH_COLUMN) as $cashId) {
            $lines += $this->posting->restampDimensions($supplierId, 'cash', (int) $cashId)['lines'];
        }
        return $lines;
    }

    /**
     * Leží některý živý zápis dokladu tam, kde ho nejde přepsat na místě (uzavřené
     * období, zamčené datum)? Rozhoduje totéž pravidlo jako dialog Přeúčtovat.
     */
    public function postedOutsideOpenPeriod(int $supplierId, string $sourceType, int $docId): bool
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT je.entry_date, ap.status
               FROM journal_entries je
               LEFT JOIN accounting_periods ap ON ap.id = je.period_id AND ap.supplier_id = je.supplier_id
              WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id = ? AND je.reversed_by IS NULL'
        );
        $stmt->execute([$supplierId, $sourceType, $docId]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($entries === []) {
            return false;
        }
        $lock = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $lock->execute([$supplierId]);
        $lockedUntil = $lock->fetchColumn();
        $lockedUntil = $lockedUntil === false || $lockedUntil === null ? null : (string) $lockedUntil;
        $today = date('Y-m-d');
        foreach ($entries as $entry) {
            $decision = DocumentRepostService::decide(
                false,
                $entry['status'] === null ? null : (string) $entry['status'],
                (string) $entry['entry_date'],
                $lockedUntil,
                null,
                $today,
            );
            if ($decision['strategy'] !== DocumentRepostService::STRATEGY_REPLACE) {
                return true;
            }
        }
        return false;
    }

    /**
     * Řádky živých (nestornovaných) zápisů dokladu i s dimenzemi.
     *
     * @return list<array{id:int, entry_id:int, account_code:?string, account_name:?string, side:string, amount:float, dimensions:array<int,int>}>
     */
    private function postedLines(int $supplierId, ?string $sourceType, int $docId): array
    {
        if ($sourceType === null) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.entry_id, a.account_code, a.name AS account_name, l.side, l.amount
               FROM journal_entries je
               JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
               LEFT JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = je.supplier_id
              WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id = ? AND je.reversed_by IS NULL
              ORDER BY je.id, l.line_no, l.id'
        );
        $stmt->execute([$supplierId, $sourceType, $docId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $dims = $this->assignments->lineDimensions($supplierId, array_map(static fn (array $r): int => (int) $r['id'], $rows));
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'entry_id' => (int) $r['entry_id'],
            'account_code' => $r['account_code'] === null ? null : (string) $r['account_code'],
            'account_name' => $r['account_name'] === null ? null : (string) $r['account_name'],
            'side' => (string) $r['side'],
            'amount' => (float) $r['amount'],
            'dimensions' => $dims[(int) $r['id']] ?? [],
        ], $rows);
    }

    /**
     * Provede `$fn` atomicky: ve vlastní transakci, nebo uvnitř transakce volajícího
     * přes savepoint (odmítnuté uložení tak po sobě nenechá půlku změn ani tam).
     * `$discard` = výsledek jen spočítat a změny zahodit (náhled).
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function atomically(callable $fn, bool $discard = false): mixed
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        $ownTx ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT dimension_document');
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $ownTx ? $pdo->rollBack() : $pdo->exec('ROLLBACK TO SAVEPOINT dimension_document');
            }
            throw $e;
        }
        if ($discard) {
            $ownTx ? $pdo->rollBack() : $pdo->exec('ROLLBACK TO SAVEPOINT dimension_document');
        } else {
            $ownTx ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT dimension_document');
        }
        return $result;
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
     * `$splits` = id řádku => typ => seznam `{value_id, share}` (rozpad řádku mezi víc
     * hodnot typu); null = rozpady ponechat. Typ s rozpadem jedinou hodnotu nemá
     * a naopak: jediná hodnota zvolená teď rozpad téhož typu zruší. Povinnou dimenzi
     * pravidla s vynucením `error` z řádku odebrat nejde (DimensionRuleService).
     *
     * @param array<int|string,mixed> $lines id řádku => mapa typ => hodnota
     * @param array<int|string,mixed>|null $splits
     * @return int počet změněných řádků
     */
    public function saveEntryLines(int $supplierId, int $entryId, array $lines, ?array $splits = null): int
    {
        $this->requireEnabled($supplierId);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT l.id, l.account_id, l.side, l.amount, je.source_type, je.entry_date
               FROM journal_entry_lines l
               JOIN journal_entries je ON je.id = l.entry_id AND je.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.entry_id = ?
              ORDER BY l.line_no, l.id'
        );
        $stmt->execute([$supplierId, $entryId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[(int) $r['id']] = $r;
        }
        if ($rows === []) {
            throw new DimensionException('not_found', 'Účetní zápis nenalezen.', 404);
        }
        $current = $this->assignments->entryLineDimensions($supplierId, $entryId);
        $currentSplits = $this->assignments->entryLineSplits($supplierId, $entryId);
        $newDims = $current;
        $newSplits = $currentSplits;
        foreach ($lines as $lineId => $dims) {
            $lineId = (int) $lineId;
            if (!isset($rows[$lineId]) || !is_array($dims)) {
                throw new DimensionException('invalid_line', 'Řádek #' . $lineId . ' do zápisu nepatří.', 400);
            }
            $newDims[$lineId] = $this->normalize($supplierId, $dims, array_values($current[$lineId] ?? []));
            if ($splits === null && isset($newSplits[$lineId])) {
                $newSplits[$lineId] = array_diff_key($newSplits[$lineId], $newDims[$lineId]);
            }
        }
        foreach ($splits ?? [] as $lineId => $byType) {
            $lineId = (int) $lineId;
            if (!isset($rows[$lineId]) || !is_array($byType)) {
                throw new DimensionException('invalid_line', 'Řádek #' . $lineId . ' do zápisu nepatří.', 400);
            }
            $currentIds = [];
            foreach ($currentSplits[$lineId] ?? [] as $shares) {
                array_push($currentIds, ...array_keys($shares));
            }
            $newSplits[$lineId] = $this->normalizeSplits($supplierId, $byType, $currentIds);
            $newDims[$lineId] = array_diff_key($newDims[$lineId] ?? [], $newSplits[$lineId]);
        }

        // Kontrolují se jen upravované řádky — starší řádek bez povinné dimenze
        // (převzatá historie) nesmí zablokovat opravu jiného řádku téhož zápisu.
        $first = reset($rows);
        $touched = array_filter($rows, static function (array $r) use ($current, $newDims, $currentSplits, $newSplits): bool {
            $id = (int) $r['id'];
            $before = $current[$id] ?? [];
            $after = $newDims[$id] ?? [];
            ksort($before);
            ksort($after);
            return $before !== $after
                || !DimensionAssignmentRepository::sameSplits($currentSplits[$id] ?? [], $newSplits[$id] ?? []);
        });
        $this->ruleService()->assertLines(
            $supplierId,
            (string) $first['source_type'],
            array_map(static fn (array $r): array => [
                'account_id' => (int) $r['account_id'],
                'side' => (string) $r['side'],
                'amount' => (float) $r['amount'],
                'dimensions' => $newDims[(int) $r['id']] ?? [],
                'dimension_splits' => $newSplits[(int) $r['id']] ?? [],
            ], array_values($touched)),
            (string) $first['entry_date'],
        );

        $changed = 0;
        foreach (array_keys($rows) as $lineId) {
            $dimsChanged = isset($newDims[$lineId])
                && $this->assignments->replaceLineDimensions($supplierId, $lineId, $newDims[$lineId]);
            $splitsChanged = ($splits !== null || isset($currentSplits[$lineId]))
                && $this->assignments->replaceLineSplits($supplierId, $lineId, $newSplits[$lineId] ?? []);
            if ($dimsChanged || $splitsChanged) {
                $changed++;
            }
        }
        return $changed;
    }

    /** @return array<int,array<int,list<array{value_id:int, share:float}>>> řádek => typ => rozpad */
    public function entryLineSplits(int $supplierId, int $entryId): array
    {
        return array_map([self::class, 'splitsForApi'], $this->assignments->entryLineSplits($supplierId, $entryId));
    }

    /**
     * @param list<int> $lineIds
     * @return array<int,array<int,list<array{value_id:int, share:float}>>> řádek => typ => rozpad
     */
    public function lineSplits(int $supplierId, array $lineIds): array
    {
        return array_map([self::class, 'splitsForApi'], $this->assignments->lineSplits($supplierId, $lineIds));
    }

    private function ruleService(): DimensionRuleService
    {
        return new DimensionRuleService($this->db);
    }

    // ── výchozí dimenze klienta a zakázky ─────────────────────────────────────

    /**
     * @param 'client'|'project' $entity
     * @return array<int,int> typ => hodnota
     */
    public function entityDefaults(int $supplierId, string $entity, int $entityId): array
    {
        $this->requireEntity($supplierId, $entity, $entityId);
        return $this->defaults->forEntity($supplierId, $entity, $entityId);
    }

    /**
     * Uloží výchozí dimenze klienta nebo zakázky. Už zaúčtované doklady se nemění —
     * výchozí hodnoty se uplatní až u dokladů, které se budou účtovat.
     *
     * @param 'client'|'project' $entity
     * @param array<int|string,mixed> $raw typ => hodnota
     * @return array<int,int>
     */
    public function saveEntityDefaults(int $supplierId, string $entity, int $entityId, array $raw): array
    {
        $this->requireEnabled($supplierId);
        $this->requireEntity($supplierId, $entity, $entityId);
        $current = $this->defaults->forEntity($supplierId, $entity, $entityId);
        $norm = $this->normalize($supplierId, $raw, array_values($current));
        $this->defaults->replace($supplierId, $entity, $entityId, $norm);
        return $norm;
    }

    /**
     * Předvyplnění hlavičky dokladu v editoru: zakázka > klient, u platby dimenze
     * placeného dokladu. Klient/zakázka cizí firmy nic nevrátí (predikát firmy).
     *
     * @return array{header:array<int,int>, sources:array<int,string>}
     */
    public function prefill(int $supplierId, ?int $clientId, ?int $projectId, ?string $linkedDocType = null, ?int $linkedDocId = null): array
    {
        if (!$this->repo->enabled($supplierId)) {
            return ['header' => [], 'sources' => []];
        }
        $result = $this->defaultsResolver->resolve($supplierId, $clientId, $projectId);
        if ($linkedDocType !== null && $linkedDocId !== null && $linkedDocId > 0
            && in_array($linkedDocType, ['invoice', 'purchase_invoice'], true)) {
            foreach ($this->defaultsResolver->effectiveHeader($supplierId, $linkedDocType, $linkedDocId) as $typeId => $valueId) {
                if (!isset($result['header'][$typeId])) {
                    $result['header'][$typeId] = $valueId;
                    $result['sources'][$typeId] = 'document';
                }
            }
            ksort($result['header']);
            ksort($result['sources']);
        }
        return $result;
    }

    /**
     * Filtr sestav na hodnotu dimenze včetně podřízených hodnot.
     */
    public function filter(int $supplierId, int $valueId, bool $withDescendants = true): DimensionFilter
    {
        $value = $this->requireValue($supplierId, $valueId);
        $ids = $withDescendants ? $this->repo->descendantIds($supplierId, $valueId) : [$valueId];
        $type = $this->repo->findType($supplierId, (int) $value['type_id']);
        return new DimensionFilter(
            $value['type_id'],
            $valueId,
            $ids,
            array_values($this->repo->costCenterCodes($supplierId, $ids)),
            trim(($type !== null ? $type['name'] . ': ' : '') . $value['code'] . ' ' . $value['name'])
                . ($withDescendants && count($ids) > 1 ? ' (vč. podřízených)' : ''),
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

    private function requireEntity(int $supplierId, string $entity, int $entityId): void
    {
        if (!in_array($entity, DimensionDefaultRepository::ENTITIES, true)
            || !$this->defaults->ownsEntity($supplierId, $entity, $entityId)) {
            throw new DimensionException('not_found', $entity === 'project' ? 'Zakázka nenalezena.' : 'Klient nenalezen.', 404);
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
