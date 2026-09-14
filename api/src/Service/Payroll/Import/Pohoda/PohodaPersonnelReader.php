<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Pohoda;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceCell;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSheet;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceText;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceWorkbookReader;

/**
 * Čte export „Tabulka agendy Personalistika" z POHODY (XLSX, případně CSV).
 *
 * Nad hlavičkou bývá blok s názvem agendy, firmou, IČ a rokem, sloupce mohou
 * ležet s prázdnými sloupci mezi sebou. Řádek hlavičky se proto hledá podle
 * buněk „Rodné číslo" a „OIC", ne podle čísla řádku. Čtení je čistě
 * syntaktické: rodné číslo ani OIČ se tu nekontrolují, to dělá náhled.
 */
final class PohodaPersonnelReader
{
    public const HEADER_SCAN_ROWS = 30;

    /** Normalizovaná hlavička → pole řádku. */
    private const COLUMNS = [
        'prijmeni' => 'last_name',
        'jmeno' => 'first_name',
        'rodne cislo' => 'birth_number',
        'osobni cislo' => 'personal_number',
        'oic' => 'oic',
    ];

    public function __construct(private readonly AttendanceWorkbookReader $workbooks) {}

    /**
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @return array{
     *   files:list<array{name:string,sha256:string,sheet:?string,row_count:int,company_ico:?string,error:?string}>,
     *   rows:list<array{
     *     file_index:int,file:string,sha256:string,sheet_index:int,sheet:string,row:int,
     *     last_name:string,first_name:string,personal_number:string,birth_number:string,oic:string
     *   }>
     * }
     */
    public function read(array $files): array
    {
        $fileRows = [];
        $rows = [];
        foreach ($files as $index => $file) {
            try {
                $sheets = $this->workbooks->read($file['name'], $index, $file['extension'], $file['content']);
            } catch (\InvalidArgumentException $e) {
                $fileRows[] = self::fileRow($file, null, 0, null, $e->getMessage());
                continue;
            }
            $found = false;
            foreach ($sheets as $sheet) {
                $header = self::header($sheet);
                if ($header === null) {
                    continue;
                }
                $found = true;
                $sheetRows = self::rows($sheet, $header, $index, $file['sha256']);
                $fileRows[] = self::fileRow(
                    $file,
                    $sheet->name,
                    count($sheetRows),
                    self::companyIco($sheet, $header['row']),
                    null,
                );
                array_push($rows, ...$sheetRows);
            }
            if (!$found) {
                $fileRows[] = self::fileRow(
                    $file,
                    null,
                    0,
                    null,
                    "V souboru „{$file['name']}“ chybí řádek hlavičky se sloupci „Rodné číslo“ a „OIC“. "
                    . 'Nahrajte export Tabulky agendy Personalistika z POHODY se sloupcem OIC.',
                );
            }
        }

        return ['files' => $fileRows, 'rows' => $rows];
    }

    /**
     * Řádek hlavičky a sloupce, ve kterých leží jednotlivé údaje. Rozhodují
     * buňky „Rodné číslo" a „OIC"; ostatní sloupce jsou nepovinné.
     *
     * @return array{row:int,columns:array<string,int>}|null
     */
    public static function header(AttendanceSheet $sheet): ?array
    {
        foreach ($sheet->rows as $rowNumber => $cells) {
            if ($rowNumber > self::HEADER_SCAN_ROWS) {
                break;
            }
            $columns = [];
            foreach ($cells as $column => $cell) {
                $field = self::COLUMNS[self::normalizeHeader($cell->textValue())] ?? null;
                if ($field !== null && !isset($columns[$field])) {
                    $columns[$field] = $column;
                }
            }
            if (isset($columns['birth_number'], $columns['oic'])) {
                return ['row' => $rowNumber, 'columns' => $columns];
            }
        }

        return null;
    }

    /** Hlavička bez diakritiky, velikosti písmen, tečky, dvojtečky a hvězdičky. */
    public static function normalizeHeader(string $value): string
    {
        return trim((string) preg_replace('/[.:*]+/u', '', AttendanceText::normalize($value)));
    }

    /**
     * Datové řádky pod hlavičkou; řádek bez rodného čísla, OIČ i jména
     * (oddělovač, prázdný řádek pod hlavičkou) se přeskočí.
     *
     * @param array{row:int,columns:array<string,int>} $header
     * @return list<array{
     *   file_index:int,file:string,sha256:string,sheet_index:int,sheet:string,row:int,
     *   last_name:string,first_name:string,personal_number:string,birth_number:string,oic:string
     * }>
     */
    private static function rows(AttendanceSheet $sheet, array $header, int $fileIndex, string $sha256): array
    {
        $result = [];
        foreach ($sheet->rows as $rowNumber => $cells) {
            if ($rowNumber <= $header['row']) {
                continue;
            }
            $values = [];
            foreach (self::COLUMNS as $field) {
                $column = $header['columns'][$field] ?? null;
                $values[$field] = $column === null ? '' : self::text($sheet->cell($rowNumber, $column), $field);
            }
            if ($values['birth_number'] === '' && $values['oic'] === ''
                && $values['last_name'] === '' && $values['first_name'] === ''
            ) {
                continue;
            }
            $result[] = [
                'file_index' => $fileIndex,
                'file' => $sheet->file,
                'sha256' => $sha256,
                'sheet_index' => $sheet->sheetIndex,
                'sheet' => $sheet->name,
                'row' => $rowNumber,
                'last_name' => $values['last_name'],
                'first_name' => $values['first_name'],
                'personal_number' => $values['personal_number'],
                'birth_number' => $values['birth_number'],
                'oic' => $values['oic'],
            ];
        }

        return $result;
    }

    /**
     * Excel ukládá OIČ zapsané jako číslo bez úvodních nul; desetimístný tvar
     * se proto u číselné buňky doplní zleva.
     */
    private static function text(AttendanceCell $cell, string $field): string
    {
        if ($cell->kind === AttendanceCell::ERROR) {
            return '';
        }
        $text = $cell->textValue();
        if ($field === 'oic' && $cell->kind === AttendanceCell::NUMBER && preg_match('/^[0-9]{1,9}$/D', $text) === 1) {
            return str_pad($text, 10, '0', STR_PAD_LEFT);
        }

        return $text;
    }

    /** IČ firmy z bloku nad hlavičkou („IČ: 12345678" v jedné buňce nebo ve dvou). */
    private static function companyIco(AttendanceSheet $sheet, int $headerRow): ?string
    {
        foreach ($sheet->rows as $rowNumber => $cells) {
            if ($rowNumber >= $headerRow) {
                break;
            }
            $labelSeen = false;
            foreach ($cells as $cell) {
                $text = trim($cell->textValue());
                if (preg_match('/^I[ČC]\s*:?\s*([0-9]{6,8})$/iu', $text, $match) === 1) {
                    return str_pad($match[1], 8, '0', STR_PAD_LEFT);
                }
                if ($labelSeen && preg_match('/^[0-9]{6,8}$/D', $text) === 1) {
                    return str_pad($text, 8, '0', STR_PAD_LEFT);
                }
                $labelSeen = preg_match('/^I[ČC]\s*:?$/iu', $text) === 1;
            }
        }

        return null;
    }

    /**
     * @param array{name:string,sha256:string} $file
     * @return array{name:string,sha256:string,sheet:?string,row_count:int,company_ico:?string,error:?string}
     */
    private static function fileRow(array $file, ?string $sheet, int $rowCount, ?string $companyIco, ?string $error): array
    {
        return [
            'name' => $file['name'],
            'sha256' => $file['sha256'],
            'sheet' => $sheet,
            'row_count' => $rowCount,
            'company_ico' => $companyIco,
            'error' => $error,
        ];
    }
}
