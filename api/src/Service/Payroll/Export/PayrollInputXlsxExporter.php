<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Protection as CellProtection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * XLSX export mzdových vstupů: list „Vstupy" (řádky filtru) a list „Info"
 * (firma, období, filtr, čas exportu).
 *
 * Formát je připravený na zpětný import upraveného souboru: poslední dva
 * sloupce `row_key` (id vstupu) a `row_version` jsou skryté a list je zamčený
 * bez hesla, odemčené zůstávají jen Množství a Částka. Import pozná vstup podle
 * `row_key` a podle `row_version` odmítne řádek, který se mezitím v aplikaci změnil.
 */
final class PayrollInputXlsxExporter
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    public const SHEET_INPUTS = 'Vstupy';
    public const SHEET_INFO = 'Info';
    public const HEADERS = [
        'Osobní číslo',
        'Jméno',
        'Vztah',
        'Kód složky',
        'Název složky',
        'Jednotka',
        'Množství',
        'Sazba',
        'Částka Kč',
        'Stav',
        'Zdroj',
        'Dávka importu',
        'row_key',
        'row_version',
    ];
    /** Sloupce, které zpětný import smí převzít; ostatní jsou jen pro orientaci. */
    public const EDITABLE_COLUMNS = ['G', 'I'];
    public const HIDDEN_COLUMNS = ['M', 'N'];
    private const WIDTHS = [14, 28, 26, 16, 32, 9, 11, 12, 14, 15, 18, 30, 10, 10];
    private const MONEY_FORMAT = '#,##0.00';

    /**
     * @param array<string,mixed> $context {@see PayrollInputExportService::context()}
     * @param iterable<array<string,mixed>> $rows řádky {@see PayrollInputExportFormatter::row()}
     * @return array{bytes:string,filename:string,mime:string,row_count:int}
     */
    public function export(array $context, iterable $rows): array
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('MyÚčto.cz')
            ->setTitle('Mzdové vstupy ' . (string) $context['period_label']);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_INPUTS);
        $columns = count(self::HEADERS);
        $lastColumn = Coordinate::stringFromColumnIndex($columns);

        foreach (self::HEADERS as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }

        $r = 2;
        $totalMinor = 0;
        foreach ($rows as $row) {
            $this->text($sheet, 1, $r, (string) $row['personal_number']);
            $this->text($sheet, 2, $r, (string) $row['name']);
            $this->text($sheet, 3, $r, (string) $row['relation']);
            $this->text($sheet, 4, $r, (string) $row['component_code']);
            $this->text($sheet, 5, $r, (string) $row['component_name']);
            $this->text($sheet, 6, $r, (string) $row['unit']);
            if ($row['quantity'] !== null) {
                $sheet->setCellValueExplicit([7, $r], $row['quantity'], DataType::TYPE_NUMERIC);
            }
            if ($row['rate'] !== null) {
                $sheet->setCellValueExplicit([8, $r], $row['rate'], DataType::TYPE_NUMERIC);
            }
            $sheet->setCellValueExplicit([9, $r], $row['amount'], DataType::TYPE_NUMERIC);
            $this->text($sheet, 10, $r, (string) $row['status']);
            $this->text($sheet, 11, $r, (string) $row['source']);
            $this->text($sheet, 12, $r, (string) $row['import']);
            $sheet->setCellValueExplicit([13, $r], $row['row_key'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit([14, $r], $row['row_version'], DataType::TYPE_NUMERIC);
            $totalMinor += (int) $row['amount_minor'];
            $r++;
        }
        $lastRow = $r - 1;
        $count = $lastRow - 1;

        $header = $sheet->getStyle("A1:{$lastColumn}1");
        $header->getFont()->setBold(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");
        if ($count > 0) {
            $sheet->getStyle("H2:I{$lastRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->getStyle("M2:N{$lastRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
            $sheet->getStyle("G2:I{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            foreach (self::EDITABLE_COLUMNS as $column) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")
                    ->getProtection()
                    ->setLocked(CellProtection::PROTECTION_UNPROTECTED);
            }
        }

        // Součet pod prázdným řádkem: mimo rozsah automatického filtru, aby ho
        // Excel nepřibral k datům, a bez `row_key`, aby ho import přeskočil.
        $totalRow = $lastRow + 2;
        $sheet->setCellValueExplicit([1, $totalRow], sprintf('Celkem (%d vstupů)', $count), DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([9, $totalRow], $totalMinor / 100, DataType::TYPE_NUMERIC);
        $sheet->getStyle("A{$totalRow}:{$lastColumn}{$totalRow}")->getFont()->setBold(true);
        $sheet->getStyle("I{$totalRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);

        foreach (self::WIDTHS as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
        foreach (self::HIDDEN_COLUMNS as $column) {
            $sheet->getColumnDimension($column)->setVisible(false);
        }

        // Zámek bez hesla chrání skryté identifikační sloupce před nechtěnou
        // úpravou; filtrovat jde dál. Formátování sloupců zůstává zamčené,
        // jinak by skryté sloupce šly jedním klikem odkrýt a přepsat.
        $protection = $sheet->getProtection();
        $protection->setSheet(true);
        $protection->setAutoFilter(false);
        $protection->setSort(false);
        $protection->setSelectLockedCells(false);
        $protection->setSelectUnlockedCells(false);

        $this->infoSheet($spreadsheet->createSheet(), $context, $count, $totalMinor);
        $spreadsheet->setActiveSheetIndex(0);

        return [
            ...$this->out($spreadsheet, (string) $context['filename_xlsx']),
            'row_count' => $count,
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    private function infoSheet(Worksheet $info, array $context, int $count, int $totalMinor): void
    {
        $info->setTitle(self::SHEET_INFO);
        $entity = (array) $context['entity'];
        $lines = [
            ['Mzdové vstupy', ''],
            ['Firma', (string) ($entity['name'] ?? '')],
            ['IČO', (string) ($entity['ico'] ?? '')],
            ['Adresa', (string) ($entity['address'] ?? '')],
            ['Období', (string) $context['period_label']],
        ];
        foreach ((array) $context['filter_lines'] as $line) {
            $lines[] = ['Filtr: ' . (string) $line['label'], (string) $line['value']];
        }
        $lines[] = ['Exportováno', (string) $context['exported_at']];
        $lines[] = ['Sazba', 'Dopočtená jako částka / množství; vstup sám sazbu neukládá.'];
        $lines[] = [
            'Skryté sloupce',
            'row_key a row_version identifikují vstup pro zpětný import, neměňte je. '
            . 'List Vstupy je proto zamčený bez hesla; filtrovat lze, pro řazení list odemkněte (Revize, Odemknout list).',
        ];

        $r = 1;
        foreach ($lines as [$label, $value]) {
            $this->text($info, 1, $r, $label);
            $this->text($info, 2, $r, $value);
            $r++;
        }
        $this->text($info, 1, $r, 'Počet vstupů');
        $info->setCellValueExplicit([2, $r], $count, DataType::TYPE_NUMERIC);
        $r++;
        $this->text($info, 1, $r, 'Součet částek Kč');
        $info->setCellValueExplicit([2, $r], $totalMinor / 100, DataType::TYPE_NUMERIC);
        $info->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $info->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $info->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $info->getStyle("A2:A{$r}")->getFont()->setBold(true);
        $info->getColumnDimension('A')->setWidth(24);
        $info->getColumnDimension('B')->setWidth(90);
        $info->getStyle("B1:B{$r}")->getAlignment()->setWrapText(true);
    }

    /**
     * Text vždy jako text. Hodnota, která by šla vyhodnotit jako vzorec,
     * dostane navíc apostrofový prefix: Excel ji ani po úpravě buňky nespustí,
     * a přitom zůstane doslova stejná pro zpětný import.
     */
    private function text(Worksheet $sheet, int $column, int $row, string $value): void
    {
        $sheet->setCellValueExplicit([$column, $row], $value, DataType::TYPE_STRING);
        if (PayrollInputExportFormatter::isFormulaLike($value)) {
            $sheet->getStyle(Coordinate::stringFromColumnIndex($column) . $row)->setQuotePrefix(true);
        }
    }

    /**
     * @return array{bytes:string,filename:string,mime:string}
     */
    private function out(Spreadsheet $spreadsheet, string $filename): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'payinp_') . '.xlsx';
        try {
            $writer = new XlsxWriter($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($tmp);
            $bytes = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
            $spreadsheet->disconnectWorksheets();
        }

        return ['bytes' => $bytes, 'filename' => $filename, 'mime' => self::MIME];
    }
}
