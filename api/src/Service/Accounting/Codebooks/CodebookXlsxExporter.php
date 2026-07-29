<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Codebooks;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * XLSX export číselníků (Epic F5 §4.3). Export = přesně importovatelný soubor
 * (round-trip → re-import 100 % skip). Druhý list „Návod" se generuje z téže
 * definice sloupců jako importní aliasy.
 *
 * Formula/CSV injection: VŠECHNY textové buňky přes setCellValueExplicit(TYPE_STRING)
 * — chrání =/+/-/@/tab a zachovává „042" s úvodní nulou. Číselné sloupce jako float/int.
 */
final class CodebookXlsxExporter
{
    private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param list<array<string,mixed>> $accounts listForTenant(includeInactive=true)
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function chartOfAccounts(array $accounts): array
    {
        $idToCode = [];
        foreach ($accounts as $a) {
            $idToCode[(int) $a['id']] = (string) $a['account_code'];
        }

        $headers = ['ucet', 'nazev', 'typ', 'strana', 'nadrizeny_ucet', 'aktivni'];
        $rows = [];
        foreach ($accounts as $a) {
            $parentCode = $a['parent_id'] !== null ? ($idToCode[(int) $a['parent_id']] ?? '') : '';
            $rows[] = [
                ['s', (string) $a['account_code']],
                ['s', (string) $a['name']],
                ['s', (string) $a['account_type']],
                ['s', $a['normal_side'] !== null ? (string) $a['normal_side'] : ''],
                ['s', $parentCode],
                ['s', ((bool) $a['is_active']) ? '1' : '0'],
            ];
        }

        $ss = new Spreadsheet();
        $this->writeSheet($ss->getActiveSheet(), 'Účtová osnova', $headers, $rows);
        $this->writeGuide($ss, ChartOfAccountsImportService::columns());

        return $this->out($ss, 'ucetni-osnova-' . date('Y-m-d') . '.xlsx');
    }

    /**
     * @param list<array<string,mixed>> $rules PostingRuleRepository::effectiveMap() values
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function postingRules(array $rules): array
    {
        $headers = ['klic', 'popis', 'md_ucet', 'd_ucet', 'aktivni', 'priorita', 'zdroj'];
        $rows = [];
        foreach ($rules as $r) {
            $rows[] = [
                ['s', (string) $r['rule_key']],
                ['s', (string) ($r['description'] ?? '')],
                ['s', $r['debit_account_code'] !== null ? (string) $r['debit_account_code'] : ''],
                ['s', $r['credit_account_code'] !== null ? (string) $r['credit_account_code'] : ''],
                ['s', ((bool) $r['is_active']) ? '1' : '0'],
                ['n', (int) $r['priority']],
                ['s', ($r['supplier_id'] ?? null) !== null ? 'firemní' : 'globální'],
            ];
        }

        $ss = new Spreadsheet();
        $this->writeSheet($ss->getActiveSheet(), 'Kontace', $headers, $rows);
        $this->writeGuide($ss, PostingRulesImportService::columns());

        return $this->out($ss, 'kontace-' . date('Y-m-d') . '.xlsx');
    }

    /**
     * @param list<array<string,mixed>> $assets AssetRepository::list() items (cast)
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function assets(array $assets): array
    {
        $columns = AssetImportService::columns();
        $headers = [];
        foreach ($columns as $def) {
            $headers[] = (string) $def['header'];
        }

        $numeric = ['input_price', 'tax_group', 'opening_tax_years', 'opening_tax_amount',
            'opening_acc_months', 'opening_acc_amount', 'acc_useful_life_months'];

        $rows = [];
        foreach ($assets as $a) {
            $row = [];
            foreach ($columns as $field => $def) {
                $v = $a[$field] ?? null;
                if (in_array($field, $numeric, true)) {
                    $row[] = $v === null || $v === '' ? ['s', ''] : ['n', $field === 'input_price' || str_contains($field, 'amount') ? (float) $v : (int) $v];
                } else {
                    $row[] = ['s', $v !== null ? (string) $v : ''];
                }
            }
            $rows[] = $row;
        }

        $ss = new Spreadsheet();
        $this->writeSheet($ss->getActiveSheet(), 'Majetek', $headers, $rows);
        $this->writeGuide($ss, $columns);

        return $this->out($ss, 'majetek-' . date('Y-m-d') . '.xlsx');
    }

    /**
     * @param list<string> $headers
     * @param list<list<array{0:string,1:mixed}>> $rows  buňka = ['s'|'n', hodnota]
     */
    private function writeSheet(Worksheet $sheet, string $title, array $headers, array $rows): void
    {
        $sheet->setTitle($title);
        $this->headerRow($sheet, $headers);

        $r = 2;
        foreach ($rows as $cells) {
            foreach ($cells as $i => $cell) {
                [$type, $value] = $cell;
                if ($type === 'n') {
                    $sheet->setCellValue([$i + 1, $r], $value);
                } else {
                    $sheet->setCellValueExplicit([$i + 1, $r], (string) $value, DataType::TYPE_STRING);
                }
            }
            $r++;
        }
        $this->autosize($sheet, count($headers));
    }

    /**
     * @param array<string,array{header:string, aliases:list<string>, required:string, note:string}> $columns
     */
    private function writeGuide(Spreadsheet $ss, array $columns): void
    {
        $sheet = $ss->createSheet();
        $headers = ['Sloupec', 'Povinný', 'Aliasy', 'Hodnoty / pravidla'];
        $sheet->setTitle('Návod');
        $this->headerRow($sheet, $headers);

        $r = 2;
        foreach ($columns as $def) {
            $sheet->setCellValueExplicit([1, $r], (string) $def['header'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $r], (string) $def['required'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $r], implode(', ', $def['aliases']), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $r], (string) $def['note'], DataType::TYPE_STRING);
            $r++;
        }
        $this->autosize($sheet, count($headers));
    }

    /** @param list<string> $headers */
    private function headerRow(Worksheet $sheet, array $headers): void
    {
        foreach ($headers as $i => $h) {
            $sheet->setCellValueExplicit([$i + 1, 1], $h, DataType::TYPE_STRING);
        }
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
    }

    private function autosize(Worksheet $sheet, int $cols): void
    {
        for ($i = 1; $i <= $cols; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
    }

    /**
     * @return array{bytes:string, filename:string, mime:string}
     */
    private function out(Spreadsheet $ss, string $filename): array
    {
        $ss->setActiveSheetIndex(0);
        $base = tempnam(sys_get_temp_dir(), 'cbexp_');
        $tmp = $base . '.xlsx';
        (new XlsxWriter($ss))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        @unlink($base);
        $ss->disconnectWorksheets();
        return ['bytes' => $bytes, 'filename' => $filename, 'mime' => self::MIME];
    }
}
