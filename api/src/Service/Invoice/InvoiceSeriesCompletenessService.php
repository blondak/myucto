<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * FR3 (vendor audit 2026-08) — report úplnosti číselné řady VYDANÝCH dokladů.
 * Mezera v řadě je auditní signál pro FÚ (§ 26 odst. 4 ZoÚ — doklady musí být číslovány
 * tak, aby byla zajištěna úplnost). `VarsymbolSeriesCollisionChecker` řeší kolizi ŠABLON
 * (dvě řady vygenerují STEJNÝ VS); tahle třída řeší opačný problém — chybějící ČÍSLO
 * v jinak zdravé řadě. Read-only — nic neopravuje, jen hlásí.
 *
 * Faktury a dobropisy MOHOU sdílet jednu řadu (stejná šablona pro `invoice_number_format`
 * i `credit_note_number_format`) — `VarsymbolGenerator` jim ale drží ODDĚLENÉ countery
 * (`invoice_type` je součástí PK `invoice_counters`); jediné, co je před vzájemnou kolizí
 * chrání, je samoopravná logika v `VarsymbolGenerator::next()`
 * ({@see \MyInvoice\Service\Invoice\VarsymbolGenerator::highestUsedCounter()}), která
 * skenuje `invoices.varsymbol` BEZ ohledu na `invoice_type`. Kontrola úplnosti proto musí
 * dělat totéž: když faktura a dobropis ve stejném scope (supplier-wide, nebo tentýž
 * klient s vlastní šablonou) vyprodukují stejný "digit skeleton"
 * ({@see VarsymbolSeriesCollisionChecker::digitSkeleton()}), sloučit jejich obsazená čísla
 * do JEDNÉ množiny — jinak by report hlásil falešné mezery přesně tam, kde číslo ve
 * skutečnosti použil ten DRUHÝ typ dokladu.
 *
 * Rozsah: report je vždy vázaný na jeden rok (`issue_date`), s výjimkou period='none'
 * (jediný globální counter bez ročního resetu), kde se vždy skenuje CELÁ historie —
 * jinak by report ročním řezem sám vyrobil falešnou mezeru na hranici roku.
 *
 * Scope se sbírá za všechny tři osy číslování, které zná
 * {@see VarsymbolGenerator::resolveTemplateAndPeriod()} — dodavatel, klient s vlastní
 * šablonou a kategorie tržby s vlastní šablonou. Vzájemné vyloučení musí kopírovat
 * PRIORITU resolveru (klient > kategorie > dodavatel), jinak by týž doklad spadl do dvou
 * skenů a vyrobil falešnou mezeru v tom, kam nepatří:
 *   - supplier-wide sken vynechá doklady klientů i kategorií s vlastní šablonou,
 *   - sken kategorie vynechá doklady klientů s vlastní šablonou (tam vyhrává klient),
 *   - sken klienta nevynechává nic (klient přebíjí kategorii bez ohledu na ni).
 */
final class InvoiceSeriesCompletenessService
{
    /** Vydané doklady dle FR3 — proforma není daňový doklad, do auditu číselné řady nepatří. */
    private const SCANNED_TYPES = ['invoice', 'credit_note'];

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    /**
     * @return list<array{
     *   types: list<string>, client_id: int, client_name: ?string,
     *   revenue_category_id: int, revenue_category_name: ?string,
     *   period: string, template_by_type: array<string,string>,
     *   buckets: list<array{
     *     period_key: string, used_count: int, range_from: int, range_to: int,
     *     missing: list<int>, missing_preview: list<string>,
     *   }>,
     * }>
     */
    public function build(int $supplierId, int $year): array
    {
        $scopes = $this->collectScopes($supplierId);
        $groups = $this->groupByDigitSkeleton($scopes);

        $result = [];
        foreach ($groups as $group) {
            $report = $this->buildGroupReport($supplierId, $group, $year);
            if ($report !== null) {
                $result[] = $report;
            }
        }
        return $result;
    }

    /**
     * Per-scope šablony (supplier-wide + každý klient a každá kategorie tržby s VLASTNÍ
     * šablonou) — stejný princip jako {@see VarsymbolSeriesCollisionChecker::collectSeries()},
     * jen navíc s obdobím (`invoice_number_period`), které report potřebuje pro bucketing.
     *
     * @return list<array{client_id:int, client_name:?string, revenue_category_id:int,
     *                    revenue_category_name:?string, period:string, templates: array<string,string>}>
     */
    private function collectScopes(int $supplierId): array
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT invoice_number_format, credit_note_number_format, invoice_number_period
               FROM supplier WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $sup = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $supplierTemplates = [];
        foreach (self::SCANNED_TYPES as $type) {
            $tpl = trim((string) ($sup["{$type}_number_format"] ?? ''));
            if ($tpl === '') {
                $tpl = trim((string) $this->config->get("varsymbol.templates.{$type}", ''));
            }
            if ($tpl !== '') {
                $supplierTemplates[$type] = $tpl;
            }
        }
        $supplierPeriod = self::normalizePeriod((string) ($sup['invoice_number_period'] ?? ''));

        $scopes = [];
        if ($supplierTemplates !== []) {
            $scopes[] = [
                'client_id'             => 0,
                'client_name'           => null,
                'revenue_category_id'   => 0,
                'revenue_category_name' => null,
                'period'                => $supplierPeriod,
                'templates'             => $supplierTemplates,
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT id, company_name, invoice_number_format, credit_note_number_format, invoice_number_period
                 FROM clients
                WHERE supplier_id = ?
                  AND (COALESCE(invoice_number_format, '') <> '' OR COALESCE(credit_note_number_format, '') <> '')"
        );
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cli) {
            $templates = self::templatesFromRow($cli);
            if ($templates === []) {
                continue;
            }
            $scopes[] = [
                'client_id'             => (int) $cli['id'],
                'client_name'           => (string) $cli['company_name'],
                'revenue_category_id'   => 0,
                'revenue_category_name' => null,
                'period'                => self::periodFromRow($cli, $supplierPeriod),
                'templates'             => $templates,
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT id, label, invoice_number_format, credit_note_number_format, invoice_number_period
                 FROM revenue_categories
                WHERE supplier_id = ?
                  AND (COALESCE(invoice_number_format, '') <> '' OR COALESCE(credit_note_number_format, '') <> '')"
        );
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cat) {
            $templates = self::templatesFromRow($cat);
            if ($templates === []) {
                continue;
            }
            $scopes[] = [
                'client_id'             => 0,
                'client_name'           => null,
                'revenue_category_id'   => (int) $cat['id'],
                'revenue_category_name' => (string) $cat['label'],
                'period'                => self::periodFromRow($cat, $supplierPeriod),
                'templates'             => $templates,
            ];
        }

        return $scopes;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    private static function templatesFromRow(array $row): array
    {
        $templates = [];
        foreach (self::SCANNED_TYPES as $type) {
            $tpl = trim((string) ($row["{$type}_number_format"] ?? ''));
            if ($tpl !== '') {
                $templates[$type] = $tpl;
            }
        }
        return $templates;
    }

    /** @param array<string,mixed> $row */
    private static function periodFromRow(array $row, string $supplierPeriod): string
    {
        return $row['invoice_number_period'] !== null && $row['invoice_number_period'] !== ''
            ? self::normalizePeriod((string) $row['invoice_number_period'])
            : $supplierPeriod;
    }

    /**
     * V rámci KAŽDÉHO scope zvlášť: má-li scope šablonu pro invoice i credit_note a obě
     * vyprodukují stejný digit skeleton, slouč je do jedné logické řady (types=[oba]).
     * Jinak zůstanou jako samostatné řady (jedna skupina na typ).
     *
     * @param list<array{client_id:int, client_name:?string, revenue_category_id:int,
     *                   revenue_category_name:?string, period:string, templates: array<string,string>}> $scopes
     * @return list<array{client_id:int, client_name:?string, revenue_category_id:int,
     *                    revenue_category_name:?string, period:string, types: list<string>,
     *                    template_by_type: array<string,string>}>
     */
    private function groupByDigitSkeleton(array $scopes): array
    {
        $groups = [];
        foreach ($scopes as $scope) {
            $identity = [
                'client_id'             => $scope['client_id'],
                'client_name'           => $scope['client_name'],
                'revenue_category_id'   => $scope['revenue_category_id'],
                'revenue_category_name' => $scope['revenue_category_name'],
                'period'                => $scope['period'],
            ];
            $types = array_keys($scope['templates']);
            if (count($types) === 2) {
                $skeletonA = VarsymbolSeriesCollisionChecker::digitSkeleton($scope['templates'][$types[0]]);
                $skeletonB = VarsymbolSeriesCollisionChecker::digitSkeleton($scope['templates'][$types[1]]);
                if ($skeletonA !== '' && $skeletonA === $skeletonB) {
                    $groups[] = $identity + [
                        'types'            => $types,
                        'template_by_type' => $scope['templates'],
                    ];
                    continue;
                }
            }
            foreach ($scope['templates'] as $type => $tpl) {
                $groups[] = $identity + [
                    'types'            => [$type],
                    'template_by_type' => [$type => $tpl],
                ];
            }
        }
        return $groups;
    }

    /**
     * @param array{client_id:int, client_name:?string, revenue_category_id:int,
     *               revenue_category_name:?string, period:string, types: list<string>,
     *               template_by_type: array<string,string>} $group
     * @return array{types: list<string>, client_id: int, client_name: ?string,
     *               revenue_category_id: int, revenue_category_name: ?string,
     *               period: string, template_by_type: array<string,string>, buckets: list<array<string,mixed>>}|null
     */
    private function buildGroupReport(int $supplierId, array $group, int $year): ?array
    {
        $bucketKeys = self::bucketKeysFor($group['period'], $year);

        // Klienti s VLASTNÍ šablonou pro daný typ nepatří ani do supplier-wide skenu, ani do
        // skenu kategorie — klient přebíjí obojí (VarsymbolGenerator::resolveTemplateAndPeriod).
        $ownClientIdsByType = $group['client_id'] === 0 ? $this->clientsWithOwnTemplate($supplierId) : [];
        // Kategorie s vlastní šablonou vypadávají navíc ze supplier-wide skenu; ve skenu
        // konkrétní kategorie se naopak nevylučuje nic (scope je právě ta kategorie).
        $ownCategoryIdsByType = $group['client_id'] === 0 && $group['revenue_category_id'] === 0
            ? $this->categoriesWithOwnTemplate($supplierId)
            : [];

        $buckets = [];
        foreach ($bucketKeys as $bucketKey) {
            $used = []; // int => true
            foreach ($group['types'] as $type) {
                $template = $group['template_by_type'][$type];
                $regex = self::counterRegex($template, $bucketKey, $group['period']);
                if ($regex === null) {
                    continue; // šablona bez {C+} — fixní číslo, nedá se "chybět"
                }
                $rows = $this->fetchVarsymbols(
                    $supplierId, $type, $group['client_id'],
                    $group['client_id'] === 0 ? ($ownClientIdsByType[$type] ?? []) : [],
                    $group['revenue_category_id'],
                    $ownCategoryIdsByType[$type] ?? [],
                    $bucketKey, $group['period'],
                );
                foreach ($rows as $vs) {
                    if (preg_match($regex, $vs, $m) === 1) {
                        $used[(int) $m[1]] = true;
                    }
                }
            }
            if ($used === []) {
                continue;
            }
            $max = max(array_keys($used));
            $missing = [];
            for ($n = 1; $n <= $max; $n++) {
                if (!isset($used[$n])) {
                    $missing[] = $n;
                }
            }
            $previewTemplate = $group['template_by_type'][$group['types'][0]];
            $buckets[] = [
                'period_key'      => $bucketKey,
                'used_count'      => count($used),
                'range_from'      => 1,
                'range_to'        => $max,
                'missing'         => $missing,
                'missing_preview' => array_map(
                    static fn (int $n): string => self::previewRender($previewTemplate, $bucketKey, $group['period'], $n),
                    $missing,
                ),
            ];
        }

        if ($buckets === []) {
            return null; // scope bez jakýchkoli dokladů v požadovaném roce — nic k hlášení
        }

        // Popisek se ZÁMĚRNĚ neskládá tady (žádný natvrdo český label v API payloadu) —
        // frontend si ho poskládá z `types` + `client_name` přes t() (AGENTS.md i18n).
        return [
            'types'                 => $group['types'],
            'client_id'             => $group['client_id'],
            'client_name'           => $group['client_name'],
            'revenue_category_id'   => $group['revenue_category_id'],
            'revenue_category_name' => $group['revenue_category_name'],
            'period'                => $group['period'],
            'template_by_type'      => $group['template_by_type'],
            'buckets'               => $buckets,
        ];
    }

    /**
     * Klienti s vlastní šablonou per typ — použije se k VYLOUČENÍ jejich dokladů ze
     * supplier-wide skenu (mají svůj vlastní, nezávislý counter).
     *
     * @return array<string, list<int>> type => list client_id
     */
    private function clientsWithOwnTemplate(int $supplierId): array
    {
        $out = ['invoice' => [], 'credit_note' => []];
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, invoice_number_format, credit_note_number_format
                 FROM clients WHERE supplier_id = ?"
        );
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            foreach (self::SCANNED_TYPES as $type) {
                if (trim((string) ($row["{$type}_number_format"] ?? '')) !== '') {
                    $out[$type][] = (int) $row['id'];
                }
            }
        }
        return $out;
    }

    /**
     * Kategorie tržeb s vlastní šablonou per typ — použije se k VYLOUČENÍ jejich dokladů
     * ze supplier-wide skenu (mají svůj vlastní, nezávislý counter).
     *
     * @return array<string, list<int>> type => list revenue_category_id
     */
    private function categoriesWithOwnTemplate(int $supplierId): array
    {
        $out = ['invoice' => [], 'credit_note' => []];
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, invoice_number_format, credit_note_number_format
                 FROM revenue_categories WHERE supplier_id = ?"
        );
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            foreach (self::SCANNED_TYPES as $type) {
                if (trim((string) ($row["{$type}_number_format"] ?? '')) !== '') {
                    $out[$type][] = (int) $row['id'];
                }
            }
        }
        return $out;
    }

    /**
     * @param list<int> $excludeClientIds
     * @param list<int> $excludeCategoryIds
     * @return list<string>
     */
    private function fetchVarsymbols(
        int $supplierId,
        string $invoiceType,
        int $clientId,
        array $excludeClientIds,
        int $revenueCategoryId,
        array $excludeCategoryIds,
        string $bucketKey,
        string $period,
    ): array {
        [$from, $to] = self::bucketDateRange($bucketKey, $period);

        $sql = "SELECT varsymbol FROM invoices
                 WHERE supplier_id = ? AND invoice_type = ?
                   AND varsymbol IS NOT NULL AND varsymbol <> ''
                   AND issue_date >= ? AND issue_date <= ?";
        $params = [$supplierId, $invoiceType, $from, $to];

        if ($clientId > 0) {
            $sql .= ' AND client_id = ?';
            $params[] = $clientId;
        } elseif ($excludeClientIds !== []) {
            $placeholders = implode(',', array_fill(0, count($excludeClientIds), '?'));
            $sql .= " AND client_id NOT IN ({$placeholders})";
            array_push($params, ...$excludeClientIds);
        }

        if ($revenueCategoryId > 0) {
            $sql .= ' AND revenue_category_id = ?';
            $params[] = $revenueCategoryId;
        } elseif ($excludeCategoryIds !== []) {
            // Doklad BEZ kategorie do supplier-wide řady patří, takže NULL musí projít —
            // samotné NOT IN by ho vyhodilo (NULL NOT IN (…) je UNKNOWN).
            $placeholders = implode(',', array_fill(0, count($excludeCategoryIds), '?'));
            $sql .= " AND (revenue_category_id IS NULL OR revenue_category_id NOT IN ({$placeholders}))";
            array_push($params, ...$excludeCategoryIds);
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /** @return array{0:string,1:string} [issue_date od, issue_date do] pro daný bucket. */
    private static function bucketDateRange(string $bucketKey, string $period): array
    {
        return match ($period) {
            'year'  => ["{$bucketKey}-01-01", "{$bucketKey}-12-31"],
            'month' => [
                substr($bucketKey, 0, 4) . '-' . substr($bucketKey, 4, 2) . '-01',
                (new \DateTimeImmutable(substr($bucketKey, 0, 4) . '-' . substr($bucketKey, 4, 2) . '-01'))
                    ->modify('last day of this month')->format('Y-m-d'),
            ],
            default => ['0001-01-01', '9999-12-31'], // 'none' — celá historie
        };
    }

    /** @return list<string> */
    private static function bucketKeysFor(string $period, int $year): array
    {
        return match ($period) {
            'year'  => [(string) $year],
            'month' => array_map(static fn (int $m): string => sprintf('%04d%02d', $year, $m), range(1, 12)),
            default => ['ALL'], // 'none' — jeden globální bucket bez ohledu na $year
        };
    }

    private static function normalizePeriod(string $period): string
    {
        return in_array($period, ['year', 'month', 'none'], true) ? $period : 'month';
    }

    /**
     * Regex pro vytažení counteru z varsymbolu — zrcadlí
     * {@see VarsymbolGenerator::buildCounterMatcher()}, jen s bucketKey/period místo
     * konkrétního data (report pracuje po obdobích, ne po jednotlivých dnech) a
     * s wildcard fallbackem pro edge-case `period='none'` + datový placeholder v šabloně
     * (counter se nikdy nereseduje, takže rok/měsíc v šabloně nejde vázat na bucket).
     */
    private static function counterRegex(string $template, string $bucketKey, string $period): ?string
    {
        if (!preg_match('/\{C+\}/', $template)) {
            return null;
        }

        [$year, $month] = self::bucketYearMonth($bucketKey, $period);

        $parts = preg_split(
            '/(\{(?:YYYY|YY|MM)(?:[+-]\d{1,3})?\}|\{C+\})/',
            $template,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        ) ?: [$template];

        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{C+\}$/', $part) === 1) {
                $regex .= '(\d+)';
                continue;
            }
            if (preg_match(InvoiceNumberFormat::DATE_TOKEN_RE, $part, $m) === 1 && $m[0] === $part) {
                // Období nefixuje rok (period='none') nebo měsíc (period='year') → wildcard
                // o šířce tokenu; posun na šířku nemá vliv.
                $value = InvoiceNumberFormat::tokenValue($m[1], (int) ($m[2] ?? 0), $year, $month);
                $regex .= $value !== null
                    ? preg_quote($value, '/')
                    : '\d{' . InvoiceNumberFormat::tokenWidth($m[1]) . '}';
                continue;
            }
            $regex .= preg_quote($part, '/');
        }
        return '/^' . $regex . '$/';
    }

    /**
     * Rok/měsíc, které dané období fixuje. `null` = období hodnotu neurčuje, takže
     * ji regex nesmí zadrátovat (roční řada nezná měsíc, `none` nezná ani rok).
     *
     * @return array{0: ?int, 1: ?int}
     */
    private static function bucketYearMonth(string $bucketKey, string $period): array
    {
        return match ($period) {
            'year'  => [(int) $bucketKey, null],
            'month' => [(int) substr($bucketKey, 0, 4), (int) substr($bucketKey, 4, 2)],
            default => [null, null],
        };
    }

    /** Náhled chybějícího čísla vyrenderovaný přes stejnou šablonu (pro čitelnost v UI). */
    private static function previewRender(string $template, string $bucketKey, string $period, int $counter): string
    {
        [$year, $month] = self::bucketYearMonth($bucketKey, $period);
        $date = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year ?? 0, $month ?? 1));

        $rendered = InvoiceNumberFormat::expandDateTokens($template, $date);
        return preg_replace_callback('/\{(C+)\}/', static function (array $m) use ($counter): string {
            return str_pad((string) $counter, strlen($m[1]), '0', STR_PAD_LEFT);
        }, $rendered) ?? $rendered;
    }
}
