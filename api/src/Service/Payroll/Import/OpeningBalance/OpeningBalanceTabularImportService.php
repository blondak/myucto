<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\OpeningBalance;

use MyInvoice\Service\Payroll\Component\PayrollInputTabularParser;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;

/**
 * Počáteční stavy kumulací z tabulky předchozího programu (CSV/XLSX).
 *
 * Dosud vedla dovnitř jediná cesta — import hlášení JMHZ
 * ({@see \MyInvoice\Service\Payroll\Import\Jmhz\JmhzOpeningBalancePlanner}).
 * Zákazník, jehož předchozí software JMHZ nevydá, musel v mřížce vyplnit
 * třináct polí krát sedm měsíců krát každý zaměstnanec. Tohle je obecná cesta:
 * jeden soubor za celou firmu, náhled, pak zápis.
 *
 * Sada sloupců se NEOPISUJE — bere se z {@see PayrollOpeningBalanceService::monthFields()},
 * tedy nakonec z `PayrollStatutoryAccumulatorRepository::VALUE_FIELDS`. Nový
 * druh kumulace se tím ve vzorovém souboru objeví sám.
 *
 * Věcnou kontrolu měsíce dělá {@see OpeningBalanceMonthValidator} a celý plán
 * prověřuje {@see PayrollOpeningBalanceService::rejectReason()} — tedy přesně
 * ta brána, kterou pak projde zápis. Náhled se nesmí ptát jinak než apply.
 *
 * Částky jsou v CELÝCH HALÉŘÍCH, stejně jako `amount_minor` v importu mzdových
 * vstupů. Koruny s desetinnou čárkou by v CSV z různých locale znamenaly tichou
 * chybu řádu.
 */
final class OpeningBalanceTabularImportService
{
    /** Sloupce identifikující řádek; za nimi následují částky. */
    private const KEY_COLUMNS = ['employee_id', 'employment_code', 'year', 'month'];

    public function __construct(
        private readonly PayrollInputTabularParser $parser,
        private readonly PayrollOpeningBalanceService $openings,
        private readonly RegistrationImportLookup $lookup,
    ) {}

    /** @return list<string> */
    public static function columns(): array
    {
        return [...self::KEY_COLUMNS, ...PayrollOpeningBalanceService::monthFields()];
    }

    /**
     * Vzorový soubor se správnou hlavičkou a jedním ukázkovým řádkem.
     *
     * Bez něj hlavičku nikdo netrefí. Oddělovač `;` a BOM kvůli Excelu
     * v českém prostředí — jinak Excel rozhodí diakritiku i sloupce.
     */
    public static function template(): string
    {
        $columns = self::columns();
        $example = [
            'employee_id' => '1',
            'employment_code' => 'HPP-001',
            'year' => (string) ((int) date('Y')),
            'month' => '1',
        ];
        $row = [];
        foreach ($columns as $column) {
            $row[] = $example[$column] ?? '0';
        }

        return "\xEF\xBB\xBF" . implode(';', $columns) . "\r\n" . implode(';', $row) . "\r\n";
    }

    /**
     * @return array{
     *   format:string,source_name:string,row_count:int,
     *   errors:list<array{row_number:int,error_code:string,field_name:?string,error_message:string}>,
     *   people:list<array<string,mixed>>
     * }
     */
    public function preview(
        int $supplierId,
        string $format,
        string $sourceName,
        string $content,
    ): array {
        $format = self::format($format);
        $sourceName = self::sourceName($sourceName);
        $parsed = $this->parser->parse($format, $content, self::columns());

        $errors = $parsed['errors'];
        $months = [];
        $seen = [];
        foreach ($parsed['rows'] as $raw) {
            $rowNumber = is_int($raw['row_number'] ?? null) ? $raw['row_number'] : 0;
            try {
                $row = $this->validateRow($supplierId, $raw);
            } catch (\InvalidArgumentException $e) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'error_code' => 'row_validation_failed',
                    'field_name' => null,
                    'error_message' => $e->getMessage(),
                ];
                continue;
            }
            $key = $row['employee_id'] . ':' . $row['year'] . ':' . $row['month'];
            if (isset($seen[$key])) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'error_code' => 'duplicate_month',
                    'field_name' => 'month',
                    'error_message' => sprintf(
                        'Měsíc %d roku %d je u zaměstnance #%d v souboru dvakrát (poprvé na řádku %d).',
                        $row['month'],
                        $row['year'],
                        $row['employee_id'],
                        $seen[$key],
                    ),
                ];
                continue;
            }
            $seen[$key] = $rowNumber;
            $months[$row['employee_id']][$row['year']][] = $row['month_row'];
        }

        $people = [];
        foreach ($months as $employeeId => $years) {
            ksort($years);
            foreach ($years as $year => $rows) {
                $people[] = $this->candidate($supplierId, (int) $employeeId, (int) $year, $rows);
            }
        }

        return [
            'format' => $format,
            'source_name' => $sourceName,
            'row_count' => count($parsed['rows']) + count($parsed['errors']),
            'errors' => $errors,
            'people' => $people,
        ];
    }

    /**
     * Zapíše to, co náhled označil jako `ready`.
     *
     * Vadný řádek shodí CELÝ soubor: rozpis počátečních stavů je souvislá řada
     * měsíců a částečně převzatá řada by tiše zkreslila počet uzavřených měsíců.
     * Zablokovaný zaměstnanec (podané hlášení) se naopak jen přeskočí — ostatní
     * osoby v souboru s ním nemají nic společného.
     *
     * Opakovaný import týchž čísel nic nezdvojí: `save()` beze změny nezapisuje
     * a náhled takový plán označí jako `unchanged`.
     *
     * @return array{saved:int,unchanged:int,skipped:list<array<string,mixed>>,
     *   source_name:string,format:string}
     */
    public function apply(
        int $supplierId,
        string $format,
        string $sourceName,
        string $content,
        ?int $userId,
    ): array {
        $preview = $this->preview($supplierId, $format, $sourceName, $content);
        if ($preview['errors'] !== []) {
            $first = $preview['errors'][0];
            throw new \InvalidArgumentException(sprintf(
                'Soubor obsahuje %d vadných řádků a nezapisuje se. První vada (řádek %d): %s',
                count($preview['errors']),
                $first['row_number'],
                $first['error_message'],
            ));
        }

        $saved = 0;
        $unchanged = 0;
        $skipped = [];
        $reference = 'opening-import:' . self::sourceName($sourceName);
        foreach ($preview['people'] as $person) {
            if ($person['status'] === 'unchanged') {
                ++$unchanged;
                continue;
            }
            if ($person['status'] !== 'ready') {
                $skipped[] = [
                    'employee_id' => $person['employee_id'],
                    'employee_name' => $person['employee_name'],
                    'year' => $person['year'],
                    'reason' => $person['reason'],
                ];
                continue;
            }
            try {
                $this->openings->save(
                    $supplierId,
                    (int) $person['employee_id'],
                    (int) $person['year'],
                    $person['months'],
                    $reference,
                    $userId,
                );
                ++$saved;
            } catch (\InvalidArgumentException | \DomainException $e) {
                $skipped[] = [
                    'employee_id' => $person['employee_id'],
                    'employee_name' => $person['employee_name'],
                    'year' => $person['year'],
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return [
            'format' => $preview['format'],
            'source_name' => $preview['source_name'],
            'saved' => $saved,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function candidate(int $supplierId, int $employeeId, int $year, array $rows): array
    {
        $public = [
            'employee_id' => $employeeId,
            'employee_name' => $this->lookup->employeeName($supplierId, $employeeId) ?? ('Osoba #' . $employeeId),
            'year' => $year,
            'months' => [],
            'status' => 'blocked',
            'reason' => null,
        ];
        $reason = $this->openings->rejectReason($supplierId, $employeeId, $year, $rows);
        if ($reason !== null) {
            return ['reason' => $reason] + $public;
        }
        $months = PayrollOpeningBalanceService::normalizedMonths($rows);
        $public['months'] = $months;
        if ($this->openings->current($supplierId, $employeeId, $year)['months'] == $months) {
            return ['status' => 'unchanged',
                'reason' => 'Počáteční stavy v evidenci už odpovídají souboru.'] + $public;
        }

        return ['status' => 'ready'] + $public;
    }

    /**
     * @param array<string,string|int> $raw
     * @return array{employee_id:int,year:int,month:int,month_row:array<string,int>}
     */
    private function validateRow(int $supplierId, array $raw): array
    {
        $employeeId = self::positiveInt($raw, 'employee_id');
        $code = trim((string) ($raw['employment_code'] ?? ''));
        if ($code === '') {
            throw new \InvalidArgumentException('Sloupec employment_code je prázdný.');
        }
        $codes = array_map(
            static fn (array $employment): string => $employment['code'],
            $this->lookup->employments($supplierId, $employeeId),
        );
        if ($codes === []) {
            throw new \InvalidArgumentException(
                "Zaměstnanec #{$employeeId} v této firmě neexistuje nebo nemá žádný pracovní vztah.",
            );
        }
        if (!in_array($code, $codes, true)) {
            // Párovací dvojice: samotné id se dá přepsat omylem a čísla by se
            // tiše zapsala jinému člověku.
            throw new \InvalidArgumentException(sprintf(
                'Označení vztahu „%s" neodpovídá zaměstnanci #%d.',
                $code,
                $employeeId,
            ));
        }
        $year = self::positiveInt($raw, 'year');
        if ($year < 2000 || $year > 2200) {
            throw new \InvalidArgumentException('Rok počátečních stavů není platný.');
        }
        $month = self::positiveInt($raw, 'month');
        if ($month > 12) {
            throw new \InvalidArgumentException('Měsíc počátečního stavu musí být číslo 1 až 12.');
        }

        $row = ['month' => $month];
        foreach (PayrollOpeningBalanceService::monthFields() as $field) {
            $row[$field] = self::amount($raw, $field);
        }
        OpeningBalanceMonthValidator::assertValid($row);

        return [
            'employee_id' => $employeeId,
            'year' => $year,
            'month' => $month,
            'month_row' => $row,
        ];
    }

    /** @param array<string,string|int> $raw */
    private static function positiveInt(array $raw, string $column): int
    {
        $value = trim((string) ($raw[$column] ?? ''));
        if (preg_match('/^\d+$/D', $value) !== 1 || (int) $value <= 0) {
            throw new \InvalidArgumentException("Sloupec {$column} musí být kladné celé číslo.");
        }

        return (int) $value;
    }

    /** @param array<string,string|int> $raw */
    private static function amount(array $raw, string $column): int
    {
        $value = trim((string) ($raw[$column] ?? ''));
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^\d+$/D', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Sloupec „%s" musí být částka v celých haléřích, nula nebo vyšší.',
                OpeningBalanceMonthValidator::label($column),
            ));
        }
        $amount = filter_var($value, FILTER_VALIDATE_INT);
        if ($amount === false) {
            throw new \InvalidArgumentException(sprintf(
                'Sloupec „%s" je mimo podporovaný rozsah.',
                OpeningBalanceMonthValidator::label($column),
            ));
        }

        return (int) $amount;
    }

    private static function format(string $value): string
    {
        $normalized = strtolower(trim($value));
        if (!in_array($normalized, ['csv', 'xlsx'], true)) {
            throw new \InvalidArgumentException('Formát musí být csv nebo xlsx.');
        }

        return $normalized;
    }

    private static function sourceName(string $value): string
    {
        $normalized = basename(str_replace('\\', '/', trim($value)));
        if ($normalized === '' || mb_strlen($normalized) > 120
            || preg_match('/[\x00-\x1F\x7F]/u', $normalized) === 1) {
            throw new \InvalidArgumentException('Název importního souboru není platný.');
        }

        return $normalized;
    }
}
