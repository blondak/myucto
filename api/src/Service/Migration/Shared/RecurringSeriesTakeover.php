<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\RecurringTemplateRepository;
use PDO;

/**
 * Pravidelné vydané faktury z historie převodu → šablony pravidelné fakturace.
 *
 * Předchozí programy (Money S3, POHODA, Premier) šablony opakovaných faktur do zálohy
 * nedávají; nájemné, paušály a servisní smlouvy ale v historii poznat jdou: stejný
 * odběratel, stejné položky, pevná částka a pravidelný rozestup období. Z každé živé
 * řady vznikne šablona podle posledního dokladu, POZASTAVENÁ a bez automatického
 * vystavení a odeslání — nic se samo nevystaví, dokud ji člověk nezkontroluje a nespustí.
 *
 * Období řady se bere z textu položky („nájem na měsíc listopad 2026", „3/2026",
 * „IV. čtvrtletí 2026"), jinak z data vystavení. Text šablony dostane místo období
 * placeholdery ({@see \MyInvoice\Service\Invoice\DescriptionPlaceholders}) se stejným
 * posunem vůči DUZP, jaký měl poslední doklad.
 *
 * Nepracuje s daty zálohy, jen s převedenými fakturami firmy — proto je sdílená pro
 * všechny převody. Opakovaný běh šablonu se stejným názvem u odběratele nezaloží znovu.
 */
final class RecurringSeriesTakeover
{
    public const STEP = 'recurring';

    private const FREQUENCIES = [1 => 'monthly', 3 => 'quarterly', 6 => 'semi_annually', 12 => 'annually'];

    private const MONTHS = [
        'leden' => 1, 'únor' => 2, 'březen' => 3, 'duben' => 4, 'květen' => 5, 'červen' => 6,
        'červenec' => 7, 'srpen' => 8, 'září' => 9, 'říjen' => 10, 'listopad' => 11, 'prosinec' => 12,
    ];

    private const ROMAN = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4];

    public function __construct(
        private readonly Connection $db,
        private readonly RecurringTemplateRepository $templates,
    ) {}

    /**
     * @return array{created:int,existing:int,templates:list<array{client:string,name:string,frequency:string,next_run_date:string}>}
     */
    public function run(int $supplierId, int $userId): array
    {
        $invoices = $this->invoices($supplierId);
        $out = ['created' => 0, 'existing' => 0, 'templates' => []];
        if ($invoices === []) {
            return $out;
        }
        $reference = new DateTimeImmutable(max(array_column($invoices, 'issue_date')));
        $pdo = $this->db->pdo();
        $exists = $pdo->prepare('SELECT 1 FROM recurring_invoice_templates WHERE supplier_id = ? AND client_id = ? AND name = ? LIMIT 1');
        foreach (self::detect($invoices, $reference) as $series) {
            $template = self::template($series);
            $exists->execute([$supplierId, $template['client_id'], $template['name']]);
            if ($exists->fetchColumn() !== false) {
                $out['existing']++;
                continue;
            }
            $data = $template + ['supplier_id' => $supplierId];
            if ($pdo->inTransaction()) {
                $id = $this->templates->create($data, $userId);
                $this->templates->replaceItems($id, $template['items']);
            } else {
                $this->templates->createWithItems($data, $userId, $template['items']);
            }
            $out['created']++;
            $out['templates'][] = [
                'client' => (string) $series['last']['client_name'],
                'name' => $template['name'],
                'frequency' => $template['frequency'],
                'next_run_date' => $template['next_run_date'],
            ];
        }
        return $out;
    }

    /**
     * Živé pravidelné řady: stejný odběratel a položky, pravidelný rozestup období
     * (měsíc, čtvrtletí, pololetí, rok), poslední dva doklady se stejnou částkou
     * (proměnné vyúčtování služeb tím vypadne) a další doklad by měl přijít nejpozději
     * jedno období po referenčním datu (= nejnovější vydaná faktura firmy).
     *
     * @param list<array<string,mixed>> $invoices doklady s klíči client_id, issue_date, items[…]
     * @return list<array{last:array<string,mixed>,months:int,count:int}>
     */
    public static function detect(array $invoices, DateTimeImmutable $reference): array
    {
        $groups = [];
        foreach ($invoices as $inv) {
            if (($inv['items'] ?? []) === []) {
                continue;
            }
            $sig = (int) $inv['client_id'] . '#' . implode('|', array_map(
                static fn (array $i): string => self::normalize((string) $i['description']) . '@' . (int) $i['vat_rate_id'] . '@' . (string) ($i['unit'] ?? ''),
                $inv['items'],
            ));
            $inv['period'] = self::period((string) $inv['items'][0]['description']) ?? self::monthIndex((string) $inv['issue_date']);
            $groups[$sig][] = $inv;
        }

        $out = [];
        $refIndex = self::monthIndex($reference->format('Y-m-d'));
        foreach ($groups as $rows) {
            if (count($rows) < 2) {
                continue;
            }
            usort($rows, static fn (array $a, array $b): int => [$a['period'], $a['issue_date']] <=> [$b['period'], $b['issue_date']]);
            $n = count($rows);
            $last = $rows[$n - 1];
            $months = $last['period'] - $rows[$n - 2]['period'];
            if (!isset(self::FREQUENCIES[$months]) || self::amounts($last) !== self::amounts($rows[$n - 2])) {
                continue;
            }
            $count = 2;
            for ($i = $n - 2; $i > 0 && $rows[$i]['period'] - $rows[$i - 1]['period'] === $months; $i--) {
                $count++;
            }
            if ($count < ($months <= 3 ? 3 : 2) || $refIndex - self::monthIndex((string) $last['issue_date']) > $months) {
                continue;
            }
            $out[] = ['last' => $last, 'months' => $months, 'count' => $count];
        }
        usort($out, static fn (array $a, array $b): int => [(string) $a['last']['client_name'], (string) $a['last']['issue_date']] <=> [(string) $b['last']['client_name'], (string) $b['last']['issue_date']]);
        return $out;
    }

    /**
     * Šablona podle posledního dokladu řady. DUZP generované faktury = den vystavení,
     * pokud ho tak řada měla, jinak poslední den předchozího měsíce (u nájmu placeného
     * zpětně); vůči němu se počítá posun období v textu.
     *
     * @param array{last:array<string,mixed>,months:int,count:int} $series
     * @return array<string,mixed>
     */
    public static function template(array $series): array
    {
        $last = $series['last'];
        $issue = new DateTimeImmutable((string) $last['issue_date']);
        $taxDate = (string) ($last['tax_date'] ?? '');
        $previousMonthEnd = $issue->modify('last day of previous month')->format('Y-m-d');
        $taxMode = $taxDate === $previousMonthEnd ? 'previous_month_last_day' : 'same_as_issue';
        $ref = $taxMode === 'previous_month_last_day' ? new DateTimeImmutable($previousMonthEnd) : $issue;

        $items = [];
        foreach ($last['items'] as $i => $item) {
            $items[] = [
                'description' => self::templatize((string) $item['description'], $ref),
                'quantity' => (float) $item['quantity'],
                'unit' => (string) ($item['unit'] ?? 'ks'),
                'unit_price_without_vat' => (float) $item['unit_price_without_vat'],
                'vat_rate_id' => (int) $item['vat_rate_id'],
                'vat_classification_code' => $item['vat_classification_code'] ?? null,
                'revenue_account_code' => $item['revenue_account_code'] ?? null,
                'order_index' => $i,
            ];
        }
        $label = trim((string) preg_replace(['/\{[^}]*\}\.?/u', '/\s+/u'], ['', ' '], $items[0]['description']), " \t+-,.");
        $day = (int) $issue->format('j');
        $endOfMonth = $day >= 28 && $day === (int) $issue->format('t');
        $next = self::addMonths($issue, $series['months']);
        $due = (string) ($last['due_date'] ?? '');

        return [
            'client_id' => (int) $last['client_id'],
            'project_id' => $last['project_id'] ?? null,
            'name' => mb_substr((string) $last['client_name'] . ' - ' . ($label !== '' ? $label : 'pravidelná faktura'), 0, 200),
            'frequency' => self::FREQUENCIES[$series['months']],
            'day_of_month' => $endOfMonth ? null : $day,
            'end_of_month' => $endOfMonth,
            'anchor_date' => $next->format('Y-m-d'),
            'next_run_date' => $next->format('Y-m-d'),
            'invoice_type' => 'invoice',
            'currency_id' => (int) $last['currency_id'],
            'language' => (string) ($last['language'] ?? 'cs'),
            'payment_method' => (string) ($last['payment_method'] ?? 'bank_transfer'),
            'reverse_charge' => !empty($last['reverse_charge']),
            'prices_include_vat' => !empty($last['prices_include_vat']),
            'discount_percent' => (float) ($last['discount_percent'] ?? 0),
            'payment_due_days' => $due !== '' ? max(0, (int) $issue->diff(new DateTimeImmutable($due))->format('%r%a')) : 14,
            'payment_due_unit' => 'days',
            'tax_date_mode' => $taxMode,
            'increment_month_in_descriptions' => false,
            'auto_issue' => false,
            'auto_send_email' => false,
            'status' => 'paused',
            'items' => $items,
        ];
    }

    /**
     * Období v textu → placeholdery s posunem vůči referenčnímu datu:
     * „listopad 2026" k 2. 10. 2026 → „{MMMM+1} {YYYY+1M}", „3/2026" → „{M+…}/{YYYY+…M}",
     * „IV. čtvrtletí 2026" → „{Q}. čtvrtletí {YYYY}", samotný rok → „{YYYY±N}".
     */
    public static function templatize(string $text, DateTimeImmutable $ref): string
    {
        $refMonth = self::monthIndex($ref->format('Y-m-d'));
        $refYear = (int) $ref->format('Y');
        $refQuarter = $refYear * 4 + intdiv((int) $ref->format('n') - 1, 3);

        $text = (string) preg_replace_callback(
            '/(?<![\p{L}\d])(IV|III|II|I|[1-4])\.(\s*)(čtvrtletí|Q)(\s+(\d{4}))?/u',
            static function (array $m) use ($refQuarter, $refYear): string {
                $q = self::ROMAN[$m[1]] ?? (int) $m[1];
                $year = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : $refYear;
                $k = $year * 4 + $q - 1 - $refQuarter;
                return '{Q' . self::offset($k) . '}.' . $m[2] . $m[3] . (isset($m[5]) && $m[5] !== '' ? ' ' . self::yearToken(3 * $k) : '');
            },
            $text,
        );

        $names = implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), array_keys(self::MONTHS)));
        $text = (string) preg_replace_callback(
            '/(?<!\p{L})(' . $names . ')(?!\p{L})(\s+(\d{4}))?/iu',
            static function (array $m) use ($refMonth, $refYear): string {
                $month = self::MONTHS[mb_strtolower($m[1])];
                if (isset($m[3]) && $m[3] !== '') {
                    $k = (int) $m[3] * 12 + $month - 1 - $refMonth;
                    return '{MMMM' . self::offset($k) . '} ' . self::yearToken($k);
                }
                // Bez roku: nejbližší výskyt měsíce k referenčnímu datu.
                $k = $refYear * 12 + $month - 1 - $refMonth;
                $k = $k > 6 ? $k - 12 : ($k < -6 ? $k + 12 : $k);
                return '{MMMM' . self::offset($k) . '}';
            },
            $text,
        );

        $text = (string) preg_replace_callback(
            '/(?<![\d.\/\-{])(\d{1,2}|\d{4})([.\/\-])(\d{4}|\d{1,2})(?![\d.\/\-])/',
            static function (array $m) use ($refMonth): string {
                if (strlen($m[1]) === 4 && strlen($m[3]) <= 2) {
                    [$year, $month, $padded, $yearFirst] = [(int) $m[1], (int) $m[3], true, true];
                } elseif (strlen($m[3]) === 4 && strlen($m[1]) <= 2) {
                    [$year, $month, $padded, $yearFirst] = [(int) $m[3], (int) $m[1], strlen($m[1]) === 2, false];
                } else {
                    return $m[0];
                }
                if ($month < 1 || $month > 12 || $year < 1990 || $year > 2099) {
                    return $m[0];
                }
                $k = $year * 12 + $month - 1 - $refMonth;
                $monthToken = '{' . ($padded ? 'MM' : 'M') . self::offset($k) . '}';
                $yearToken = self::yearToken($k);
                return $yearFirst ? $yearToken . $m[2] . $monthToken : $monthToken . $m[2] . $yearToken;
            },
            $text,
        );

        return (string) preg_replace_callback(
            '/(?<![\d.\/\-{+])((?:19|20)\d{2})(?![\d.\/\-}M])/',
            static fn (array $m): string => '{YYYY' . self::offset((int) $m[1] - $refYear) . '}',
            $text,
        );
    }

    /** Index měsíce období v textu (první výskyt), nebo null. */
    private static function period(string $text): ?int
    {
        $names = implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), array_keys(self::MONTHS)));
        if (preg_match('/(?<!\p{L})(' . $names . ')(?!\p{L})\s+(\d{4})/iu', $text, $m) === 1) {
            return (int) $m[2] * 12 + self::MONTHS[mb_strtolower($m[1])] - 1;
        }
        if (preg_match('/(?<![\p{L}\d])(IV|III|II|I|[1-4])\.\s*(?:čtvrtletí|Q)\s+(\d{4})/u', $text, $m) === 1) {
            return (int) $m[2] * 12 + ((self::ROMAN[$m[1]] ?? (int) $m[1]) - 1) * 3;
        }
        if (preg_match('/(?<![\d.\/\-])(\d{1,2})[.\/\-](\d{4})(?![\d.\/\-])/', $text, $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
            return (int) $m[2] * 12 + (int) $m[1] - 1;
        }
        return null;
    }

    /** Text položky bez období a čísel — klíč, podle kterého se doklady řadí do řady. */
    private static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/(?<!\p{L})(' . implode('|', array_keys(self::MONTHS)) . ')(?!\p{L})/u', ' ', $text);
        $text = (string) preg_replace('/(?<!\p{L})(iv|iii|ii|i)\.\s*(?=čtvrtletí)/u', ' ', $text);
        $text = (string) preg_replace('/\d+/', '#', $text);
        return trim((string) preg_replace('/[\s#.\/\-]+/u', ' ', $text));
    }

    /** @param array<string,mixed> $invoice */
    private static function amounts(array $invoice): string
    {
        return implode('|', array_map(
            static fn (array $i): string => sprintf('%.3f*%.2f', (float) $i['quantity'], (float) $i['unit_price_without_vat']),
            $invoice['items'],
        ));
    }

    private static function offset(int $k): string
    {
        return $k === 0 ? '' : sprintf('%+d', $k);
    }

    /** Rok měsíce posunutého o $k měsíců; bez posunu prostý {YYYY}. */
    private static function yearToken(int $k): string
    {
        return $k === 0 ? '{YYYY}' : '{YYYY' . sprintf('%+d', $k) . 'M}';
    }

    private static function monthIndex(string $date): int
    {
        return (int) substr($date, 0, 4) * 12 + (int) substr($date, 5, 2) - 1;
    }

    private static function addMonths(DateTimeImmutable $d, int $months): DateTimeImmutable
    {
        $first = $d->modify('first day of this month')->modify(sprintf('%+d months', $months));
        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min((int) $d->format('j'), (int) $first->format('t')));
    }

    /** @return list<array<string,mixed>> */
    private function invoices(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT i.id, i.client_id, i.project_id, i.issue_date, i.tax_date, i.due_date, i.currency_id, i.language,
                    i.payment_method, i.reverse_charge, i.prices_include_vat, i.discount_percent, c.company_name AS client_name
               FROM invoices i
               JOIN clients c ON c.id = i.client_id
              WHERE i.supplier_id = ? AND i.invoice_type = 'invoice' AND i.status NOT IN ('draft', 'cancelled')
                AND i.recurring_template_id IS NULL AND i.total_with_vat > 0"
        );
        $stmt->execute([$supplierId]);
        $invoices = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $invoices[(int) $row['id']] = $row + ['items' => []];
        }
        if ($invoices === []) {
            return [];
        }
        $items = $pdo->prepare(
            "SELECT ii.invoice_id, ii.description, ii.quantity, ii.unit, ii.unit_price_without_vat, ii.vat_rate_id,
                    ii.vat_classification_code, ii.revenue_account_code
               FROM invoice_items ii
               JOIN invoices i ON i.id = ii.invoice_id
              WHERE i.supplier_id = ? AND i.invoice_type = 'invoice' AND ii.item_kind = 'standard'
              ORDER BY ii.invoice_id, ii.order_index, ii.id"
        );
        $items->execute([$supplierId]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if (isset($invoices[(int) $item['invoice_id']])) {
                $invoices[(int) $item['invoice_id']]['items'][] = $item;
            }
        }
        return array_values($invoices);
    }
}
