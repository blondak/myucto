<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\GoPay;

use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Výpis z obchodního účtu GoPay (XLS/XLSX) převedený na stejný tvar jako
 * {@see GoPayClearingXmlParser::parse()}.
 *
 * Výpis není clearing: nese pohyby účtu za zvolené období, ale bez GoPay ID
 * platby a bez ID pohybu. Výplata (GOPAY-vyuctovani) a poplatek (GOPAY-popl)
 * clearingu jsou ve výpisu období, kdy clearing proběhl, tedy obvykle až ve
 * výpisu za další měsíc, a nesou ID clearingu ve sloupci „ID objednávky/VS".
 * Výplatu a poplatek proto identifikuje stejně jako XML ('clearing:<id>:payout'),
 * aby se tentýž clearing nezaúčtoval podruhé, ať přišel z XML, nebo z výpisu.
 */
final class GoPayStatementXlsxParser
{
    private const MAX_BYTES = 2_097_152;
    private const MAX_UNCOMPRESSED_BYTES = 20_971_520;
    private const MAX_ROWS = 5000;
    private const MAX_COLUMNS = 40;

    private const TYPES = [
        'GOPAY-platba' => 'credit',
        'GOPAY-storno-platby' => 'storno',
        'GOPAY-storno-popl' => 'storno_fee',
        'GOPAY-popl' => 'clearing_fee',
        'GOPAY-vyuctovani' => 'payout',
    ];

    public static function isSpreadsheet(string $content): bool
    {
        return str_starts_with($content, "PK\x03\x04") || str_starts_with($content, "\xD0\xCF\x11\xE0");
    }

    /**
     * @return array{
     *   file_format:string,clearing_id:string,account_name:string,currency:string,variable_symbol:string,
     *   cleared_from:string,cleared_to:string,performed_on:string,
     *   amount_gross:string,amount_credit_note:string,amount_fee:string,
     *   amount_fee_external:string,amount_storno:string,amount_storno_fee:string,
     *   amount_transfer:string,amount_sent:string,
     *   movements:list<array<string,?string>>
     * }
     */
    public function parse(string $content): array
    {
        if ($content === '' || strlen($content) > self::MAX_BYTES) {
            throw new GoPayException('invalid_file_size', 'Výpis GoPay je prázdný nebo překračuje limit 2 MB.');
        }
        $format = str_starts_with($content, "PK\x03\x04") ? 'xlsx' : 'xls';
        $matrix = $this->read($content, $format);

        $title = false;
        $labels = [];
        $headerRow = null;
        $columns = [];
        foreach ($matrix as $index => $row) {
            foreach ($row as $col => $cell) {
                $text = $this->text($cell);
                if ($text === '') {
                    continue;
                }
                if (mb_strtoupper($text) === 'VÝPIS Z OBCHODNÍHO ÚČTU') {
                    $title = true;
                } elseif (preg_match('/^EVČ:\s*([0-9]{1,20})$/u', $text, $m) === 1) {
                    $labels['evc'] = $m[1];
                } elseif (preg_match('/^ID účtu:\s*(.+)$/u', $text, $m) === 1) {
                    $labels['account'] = trim($m[1]);
                } elseif ($headerRow === null && $text === 'Datum' && $this->isMovementHeader($row)) {
                    $headerRow = $index;
                    foreach ($row as $headerCol => $header) {
                        $columns[$this->text($header)] = $headerCol;
                    }
                } else {
                    $labels[$text] ??= $this->valueRightOf($row, $col);
                }
            }
        }
        if (!$title || $headerRow === null || !isset($labels['evc'], $labels['account'])) {
            throw new GoPayException('invalid_statement', 'Soubor není výpis z obchodního účtu GoPay.');
        }

        $accountName = mb_substr(preg_replace('/^[0-9]+\s*\/\s*/', '', $labels['account']) ?? '', 0, 190);
        if (preg_match('/(?:^|\s)([A-Z]{3})$/', $accountName, $currencyMatch) !== 1) {
            throw new GoPayException('currency_unknown', 'Z názvu GoPay účtu nelze bezpečně určit měnu.');
        }
        $currency = $currencyMatch[1];

        if (preg_match('/^(.+?)\s+-\s+(.+)$/u', (string) ($labels['Definované období'] ?? ''), $period) !== 1) {
            throw new GoPayException('invalid_statement', 'Ve výpisu GoPay chybí definované období.');
        }
        $from = $this->date($period[1]);
        $to = $this->date($period[2]);
        if ($from > $to) {
            throw new GoPayException('invalid_statement', 'Období výpisu GoPay je neplatné.');
        }
        $opening = $this->summaryCents($labels, 'Počáteční zůstatek na účtě', $currency);
        $credited = $this->summaryCents($labels, 'Připsáno na účet', $currency);
        $debited = $this->summaryCents($labels, 'Odepsáno z účtu', $currency);
        $closing = $this->summaryCents($labels, 'Konečný zůstatek na účtě', $currency);

        $rows = $this->movementRows($matrix, $headerRow, $columns, $currency, $from, $to);
        $plus = 0;
        $minus = 0;
        foreach ($rows as $row) {
            if ($row['cents'] > 0) {
                $plus += $row['cents'];
            } else {
                $minus += $row['cents'];
            }
        }
        if ($plus !== $credited || $minus !== -abs($debited) || $opening + $plus + $minus !== $closing) {
            throw new GoPayException('summary_mismatch', 'Součet pohybů neodpovídá souhrnu výpisu GoPay.');
        }

        $movements = [];
        $occurrences = [];
        $clearingTotals = [];
        foreach ($rows as $row) {
            if ($row['type'] === 'payout' || $row['type'] === 'clearing_fee') {
                if (preg_match('/^[0-9]{1,20}$/', $row['reference']) !== 1) {
                    throw new GoPayException('invalid_attribute', 'Výplata GoPay nemá platné ID vyúčtování.');
                }
                $key = $row['reference'] . ':' . $row['type'];
                $clearingTotals[$key] ??= ['cents' => 0] + $row;
                $clearingTotals[$key]['cents'] += $row['cents'];
                continue;
            }
            $order = $row['reference'];
            if ($order !== '' && preg_match('/^[A-Za-z0-9._:\/-]{1,80}$/', $order) !== 1) {
                throw new GoPayException('invalid_attribute', 'Číslo objednávky ve výpisu GoPay má neplatný formát.');
            }
            $identity = implode('|', [$row['type'], $row['date'], $order, $row['cents']]);
            $occurrences[$identity] = ($occurrences[$identity] ?? 0) + 1;
            $movements[] = [
                'external_id'         => 'xlsx:' . substr(hash('sha256', $identity . '|' . $occurrences[$identity]), 0, 40),
                'movement_type'       => $row['type'],
                'performed_on'        => $row['date'],
                'amount'              => $this->decimal($row['cents']),
                'order_id'            => $order !== '' ? $order : null,
                'payment_session_id'  => null,
                'account_movement_id' => null,
                'payment_channel'     => null,
                'counterparty_name'   => $row['counterparty'] !== '' ? mb_substr($row['counterparty'], 0, 190) : null,
            ];
        }

        $payoutIds = [];
        foreach ($clearingTotals as $total) {
            if ($total['cents'] >= 0) {
                throw new GoPayException('invalid_movement_amount', 'Výplata a poplatek vyúčtování musí mít zápornou částku.');
            }
            $movements[] = [
                'external_id'         => 'clearing:' . $total['reference'] . ':' . $total['type'],
                'movement_type'       => $total['type'],
                'performed_on'        => $total['date'],
                'amount'              => $this->decimal($total['cents']),
                'order_id'            => null,
                'payment_session_id'  => null,
                'account_movement_id' => null,
                'payment_channel'     => null,
                'counterparty_name'   => $total['type'] === 'payout' && $total['counterparty'] !== ''
                    ? mb_substr($total['counterparty'], 0, 190) : 'GoPay',
                'clearing_reference'  => $total['reference'],
            ];
            if ($total['type'] === 'payout') {
                $payoutIds[$total['reference']] = true;
            }
        }
        if (count($payoutIds) > 1) {
            throw new GoPayException(
                'multiple_payouts',
                'Výpis GoPay obsahuje výplaty více vyúčtování. Nahraj XML jednotlivých vyúčtování nebo výpis za kratší období.',
            );
        }

        $clearingId = 'VYPIS-' . $labels['evc'] . '-' . str_replace('-', '', $from) . '-' . str_replace('-', '', $to);
        return ['file_format' => $format, 'account_name' => $accountName, 'currency' => $currency]
            + self::summarize($clearingId, $from, $to, $movements)
            + ['movements' => $movements];
    }

    /**
     * Hlavička záznamu výpisu počítaná z pohybů, které si záznam ponechá. Výplatu
     * a poplatek clearingu, který už je evidovaný jinde, import vyřadí a hlavička
     * je pak nesmí uvádět, jinak by se výplata párovala s bankou dvakrát.
     *
     * @param list<array<string,?string>> $movements
     * @return array<string,string>
     */
    public static function summarize(string $clearingId, string $from, string $to, array $movements): array
    {
        $sums = ['credit' => 0, 'storno' => 0, 'storno_fee' => 0, 'clearing_fee' => 0, 'payout' => 0];
        $payoutVs = '';
        $performedOn = $to;
        foreach ($movements as $movement) {
            $type = (string) $movement['movement_type'];
            $sums[$type] = ($sums[$type] ?? 0) + abs(self::cents((string) $movement['amount']));
            if ($type === 'payout') {
                $payoutVs = (string) ($movement['clearing_reference'] ?? '');
                $performedOn = (string) $movement['performed_on'];
            }
        }
        $decimal = static fn (int $cents): string => intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
        return [
            'clearing_id'         => $clearingId,
            'variable_symbol'     => $payoutVs,
            'cleared_from'        => $from,
            'cleared_to'          => $to,
            'performed_on'        => $performedOn,
            'amount_gross'        => $decimal($sums['credit']),
            'amount_credit_note'  => '0.00',
            'amount_fee'          => $decimal($sums['clearing_fee']),
            'amount_fee_external' => '0.00',
            'amount_storno'       => $decimal($sums['storno']),
            'amount_storno_fee'   => $decimal($sums['storno_fee']),
            'amount_transfer'     => $decimal($sums['payout']),
            'amount_sent'         => $decimal($sums['payout']),
        ];
    }

    /** @return list<array<int,mixed>> */
    private function read(string $content, string $format): array
    {
        $base = tempnam(sys_get_temp_dir(), 'gopay_');
        if ($base === false) {
            throw new \RuntimeException('Nelze vytvořit dočasný soubor.');
        }
        $tmp = $base . '.' . $format;
        file_put_contents($tmp, $content);
        try {
            if ($format === 'xlsx') {
                $zip = new \ZipArchive();
                if ($zip->open($tmp) !== true) {
                    throw new GoPayException('invalid_statement', 'Soubor nelze přečíst jako XLSX.');
                }
                $uncompressed = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $uncompressed += (int) ($zip->statIndex($i)['size'] ?? 0);
                }
                $zip->close();
                if ($uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new GoPayException('invalid_file_size', 'Výpis GoPay je po rozbalení příliš velký.');
                }
            }
            $reader = IOFactory::createReader($format === 'xlsx' ? 'Xlsx' : 'Xls');
            $reader->setReadDataOnly(true);
            try {
                $spreadsheet = $reader->load($tmp);
            } catch (\Throwable) {
                throw new GoPayException('invalid_statement', 'Soubor nelze přečíst jako tabulku XLS/XLSX.');
            }
            $sheet = $spreadsheet->getSheet(0);
            $highestRow = $sheet->getHighestDataRow();
            $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            if ($highestRow > self::MAX_ROWS || $highestCol > self::MAX_COLUMNS) {
                throw new GoPayException('invalid_file_size', 'Výpis GoPay má příliš mnoho řádků nebo sloupců.');
            }
            $matrix = [];
            for ($r = 1; $r <= $highestRow; $r++) {
                $cells = [];
                for ($c = 1; $c <= $highestCol; $c++) {
                    $cells[$c] = $sheet->getCell([$c, $r])->getValue();
                }
                $matrix[] = $cells;
            }
            $spreadsheet->disconnectWorksheets();
            return $matrix;
        } finally {
            @unlink($tmp);
            @unlink($base);
        }
    }

    /** @param array<int,mixed> $row */
    private function isMovementHeader(array $row): bool
    {
        $headers = array_map($this->text(...), $row);
        return in_array('Typ', $headers, true) && in_array('Částka', $headers, true);
    }

    /** @param array<int,mixed> $row */
    private function valueRightOf(array $row, int $col): string
    {
        foreach ($row as $index => $cell) {
            if ($index > $col && $this->text($cell) !== '') {
                return $this->text($cell);
            }
        }
        return '';
    }

    /**
     * @param list<array<int,mixed>> $matrix
     * @param array<string,int> $columns
     * @return list<array{type:string,date:string,cents:int,reference:string,counterparty:string}>
     */
    private function movementRows(array $matrix, int $headerRow, array $columns, string $currency, string $from, string $to): array
    {
        foreach (['Datum', 'Typ', 'Částka'] as $required) {
            if (!isset($columns[$required])) {
                throw new GoPayException('invalid_statement', 'Ve výpisu GoPay chybí sloupec ' . $required . '.');
            }
        }
        $referenceCol = null;
        foreach ($columns as $name => $col) {
            if (str_starts_with($name, 'ID objednávky')) {
                $referenceCol = $col;
            }
        }
        $rows = [];
        foreach (array_slice($matrix, $headerRow + 1) as $row) {
            $rawType = $this->text($row[$columns['Typ']] ?? null);
            $rawDate = $row[$columns['Datum']] ?? null;
            $rawAmount = $row[$columns['Částka']] ?? null;
            if ($rawType === '' && $this->text($rawDate) === '' && $this->text($rawAmount) === '') {
                continue;
            }
            $type = self::TYPES[$rawType] ?? null;
            if ($type === null) {
                throw new GoPayException('unsupported_movement', 'Výpis GoPay obsahuje nepodporovaný typ pohybu ' . mb_substr($rawType, 0, 40) . '.');
            }
            $rowCurrency = isset($columns['Měna']) ? $this->text($row[$columns['Měna']] ?? null) : $currency;
            if ($rowCurrency !== $currency) {
                throw new GoPayException('currency_unknown', 'Pohyb výpisu GoPay je v jiné měně než účet.');
            }
            $date = $this->date($rawDate);
            if ($date < $from || $date > $to) {
                throw new GoPayException('invalid_date', 'Pohyb výpisu GoPay leží mimo období výpisu.');
            }
            $cents = $this->amountCents($rawAmount);
            if ($cents === 0 || ($type === 'credit') !== ($cents > 0)) {
                throw new GoPayException('invalid_movement_amount', 'Pohyb výpisu GoPay má neplatné znaménko částky.');
            }
            $rows[] = [
                'type' => $type,
                'date' => $date,
                'cents' => $cents,
                'reference' => $referenceCol !== null ? $this->text($row[$referenceCol] ?? null) : '',
                'counterparty' => isset($columns['Protistrana'])
                    ? (string) preg_replace('/[\x00-\x1f\x7f]/u', '', $this->text($row[$columns['Protistrana']] ?? null))
                    : '',
            ];
        }
        return $rows;
    }

    /** @param array<string,string> $labels */
    private function summaryCents(array $labels, string $label, string $currency): int
    {
        $value = $labels[$label] ?? '';
        if ($value === '' || preg_match('/^(-?[0-9 ]+(?:,[0-9]{1,2})?)\s*' . $currency . '$/u', $value, $m) !== 1) {
            throw new GoPayException('invalid_statement', 'Ve výpisu GoPay chybí nebo je neplatný údaj ' . $label . '.');
        }
        return $this->amountCents(str_replace(' ', '', $m[1]));
    }

    private function amountCents(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            return (int) round(round((float) $value, 4) * 100);
        }
        $text = str_replace([' ', ','], ['', '.'], $this->text($value));
        if (preg_match('/^-?[0-9]{1,12}(?:\.[0-9]{1,2})?$/', $text) !== 1) {
            throw new GoPayException('invalid_amount', 'Výpis GoPay obsahuje neplatnou částku.');
        }
        return self::cents($text);
    }

    private function date(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }
        $text = str_replace(' ', '', $this->text($value));
        $date = DateTimeImmutable::createFromFormat('!j.n.Y', $text);
        if ($date === false || $date->format('j.n.Y') !== $text) {
            throw new GoPayException('invalid_date', 'Výpis GoPay obsahuje neplatné datum.');
        }
        return $date->format('Y-m-d');
    }

    private function text(mixed $value): string
    {
        if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $value = $value->getPlainText();
        }
        return is_scalar($value) ? trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', (string) $value)) : '';
    }

    private static function cents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $plain = $negative ? substr($amount, 1) : $amount;
        [$whole, $fraction] = array_pad(explode('.', $plain, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        return $negative ? -$cents : $cents;
    }

    private function decimal(int $cents): string
    {
        $absolute = abs($cents);
        return ($cents < 0 ? '-' : '') . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
