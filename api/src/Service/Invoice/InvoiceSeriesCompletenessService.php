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
 * ZNÁMÉ OMEZENÍ: řady kategorií tržeb (`revenue_categories.*_number_format`, migrace
 * 1333) tenhle sken zatím NEPOKRÝVÁ — scope se sbírá jen za dodavatele a klienty.
 * Falešné mezery to nedělá (doklad s číslem z řady kategorie má jiný literál, takže
 * regexu supplier-wide řady neodpovídá, a counter supplier-wide řady neinkrementoval);
 * jen pro tyhle řady zatím report nevzniká. Pokud by šablona kategorie měla TÝŽ digit
 * skeleton jako supplier-wide řada, jde o kolizi, kterou hlásí
 * {@see VarsymbolSeriesCollisionChecker} — řeší se tam, ne tady.
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
     * Per-scope šablony (supplier-wide + každý klient s VLASTNÍ šablonou) — stejný
     * princip jako {@see VarsymbolSeriesCollisionChecker::collectSeries()}, jen navíc
     * s obdobím (`invoice_number_period`), které report potřebuje pro bucketing.
     *
     * @return list<array{client_id:int, client_name:?string, period:string, templates: array<string,string>}>
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
                'client_id'   => 0,
                'client_name' => null,
                'period'      => $supplierPeriod,
                'templates'   => $supplierTemplates,
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
            $templates = [];
            foreach (self::SCANNED_TYPES as $type) {
                $tpl = trim((string) ($cli["{$type}_number_format"] ?? ''));
                if ($tpl !== '') {
                    $templates[$type] = $tpl;
                }
            }
            if ($templates === []) {
                continue;
            }
            $period = $cli['invoice_number_period'] !== null && $cli['invoice_number_period'] !== ''
                ? self::normalizePeriod((string) $cli['invoice_number_period'])
                : $supplierPeriod;
            $scopes[] = [
                'client_id'   => (int) $cli['id'],
                'client_name' => (string) $cli['company_name'],
                'period'      => $period,
                'templates'   => $templates,
            ];
        }

        return $scopes;
    }

    /**
     * V rámci KAŽDÉHO scope zvlášť: má-li scope šablonu pro invoice i credit_note a obě
     * vyprodukují stejný digit skeleton, slouč je do jedné logické řady (types=[oba]).
     * Jinak zůstanou jako samostatné řady (jedna skupina na typ).
     *
     * @param list<array{client_id:int, client_name:?string, period:string, templates: array<string,string>}> $scopes
     * @return list<array{client_id:int, client_name:?string, period:string, types: list<string>, template_by_type: array<string,string>}>
     */
    private function groupByDigitSkeleton(array $scopes): array
    {
        $groups = [];
        foreach ($scopes as $scope) {
            $types = array_keys($scope['templates']);
            if (count($types) === 2) {
                $skeletonA = VarsymbolSeriesCollisionChecker::digitSkeleton($scope['templates'][$types[0]]);
                $skeletonB = VarsymbolSeriesCollisionChecker::digitSkeleton($scope['templates'][$types[1]]);
                if ($skeletonA !== '' && $skeletonA === $skeletonB) {
                    $groups[] = [
                        'client_id'        => $scope['client_id'],
                        'client_name'      => $scope['client_name'],
                        'period'           => $scope['period'],
                        'types'            => $types,
                        'template_by_type' => $scope['templates'],
                    ];
                    continue;
                }
            }
            foreach ($scope['templates'] as $type => $tpl) {
                $groups[] = [
                    'client_id'        => $scope['client_id'],
                    'client_name'      => $scope['client_name'],
                    'period'           => $scope['period'],
                    'types'            => [$type],
                    'template_by_type' => [$type => $tpl],
                ];
            }
        }
        return $groups;
    }

    /**
     * @param array{client_id:int, client_name:?string, period:string, types: list<string>, template_by_type: array<string,string>} $group
     * @return array{types: list<string>, client_id: int, client_name: ?string,
     *               period: string, template_by_type: array<string,string>, buckets: list<array<string,mixed>>}|null
     */
    private function buildGroupReport(int $supplierId, array $group, int $year): ?array
    {
        $bucketKeys = self::bucketKeysFor($group['period'], $year);

        // Klienti s VLASTNÍ šablonou pro daný typ nepatří do supplier-wide skenu — jejich
        // doklady jedou pod vlastním counterem (viz VarsymbolGenerator::resolveTemplateAndPeriod).
        $ownClientIdsByType = $group['client_id'] === 0 ? $this->clientsWithOwnTemplate($supplierId) : [];

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
            'types'            => $group['types'],
            'client_id'        => $group['client_id'],
            'client_name'      => $group['client_name'],
            'period'           => $group['period'],
            'template_by_type' => $group['template_by_type'],
            'buckets'          => $buckets,
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
     * @param list<int> $excludeClientIds
     * @return list<string>
     */
    private function fetchVarsymbols(
        int $supplierId,
        string $invoiceType,
        int $clientId,
        array $excludeClientIds,
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

        $year = ''; $year2 = ''; $month = '';
        if ($period === 'year') {
            $year = $bucketKey;
            $year2 = substr($bucketKey, 2, 2);
        } elseif ($period === 'month') {
            $year = substr($bucketKey, 0, 4);
            $year2 = substr($year, 2, 2);
            $month = substr($bucketKey, 4, 2);
        }

        $marked = preg_replace(
            ['/\{YYYY\}/', '/\{YY\}/', '/\{MM\}/', '/\{C+\}/'],
            ["\x00Y4\x00", "\x00Y2\x00", "\x00M2\x00", "\x00C\x00"],
            $template,
        ) ?? $template;

        $parts = preg_split("/(\x00Y4\x00|\x00Y2\x00|\x00M2\x00|\x00C\x00)/", $marked, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$marked];

        $regex = '';
        foreach ($parts as $part) {
            $regex .= match ($part) {
                "\x00Y4\x00" => $year !== '' ? preg_quote($year, '/') : '\d{4}',
                "\x00Y2\x00" => $year2 !== '' ? preg_quote($year2, '/') : '\d{2}',
                "\x00M2\x00" => $month !== '' ? preg_quote($month, '/') : '\d{2}',
                "\x00C\x00"  => '(\d+)',
                default      => preg_quote($part, '/'),
            };
        }
        return '/^' . $regex . '$/';
    }

    /** Náhled chybějícího čísla vyrenderovaný přes stejnou šablonu (pro čitelnost v UI). */
    private static function previewRender(string $template, string $bucketKey, string $period, int $counter): string
    {
        $year = '0000'; $month = '01';
        if ($period === 'year') {
            $year = $bucketKey;
        } elseif ($period === 'month') {
            $year = substr($bucketKey, 0, 4);
            $month = substr($bucketKey, 4, 2);
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', "{$year}-{$month}-01") ?: new \DateTimeImmutable('today');

        $rendered = strtr($template, [
            '{YYYY}' => $date->format('Y'),
            '{YY}'   => $date->format('y'),
            '{MM}'   => $date->format('m'),
        ]);
        return preg_replace_callback('/\{(C+)\}/', static function (array $m) use ($counter): string {
            return str_pad((string) $counter, strlen($m[1]), '0', STR_PAD_LEFT);
        }, $rendered) ?? $rendered;
    }
}
