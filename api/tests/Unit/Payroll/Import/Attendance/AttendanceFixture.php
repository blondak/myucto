<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceCp1250;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

/**
 * Syntetické podklady docházky (jména, rodná čísla i čísla jsou vymyšlená).
 *
 * Sešity se staví přes PhpSpreadsheet. Uložené hodnoty vzorců — včetně
 * `#REF!` — se do XML listu doplní ručně: writer je bez přepočtu nezapisuje
 * a přepočet je přesně to, co import dělat nesmí. Díky tomu jde uložit
 * hodnotu, která se od přepočtu liší, a ověřit, že se bere ta uložená.
 */
final class AttendanceFixture
{
    public const PERIOD = '2026-06';

    /**
     * @param array<string,array{rows:array<int,array<string,string|int|float|null>>,formats?:array<string,string>,cached?:array<string,int|float|string>}> $sheets
     */
    public static function xlsx(array $sheets): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        $cachedBySheet = [];
        $index = 0;
        foreach ($sheets as $title => $definition) {
            $sheet = $spreadsheet->createSheet($index);
            $sheet->setTitle($title);
            foreach ($definition['rows'] as $row => $cells) {
                foreach ($cells as $column => $value) {
                    if ($value === null) {
                        continue;
                    }
                    $coordinate = $column . $row;
                    if (is_string($value) && str_starts_with($value, '=')) {
                        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_FORMULA);
                    } elseif (is_string($value)) {
                        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
                    } else {
                        $sheet->setCellValue($coordinate, $value);
                    }
                }
            }
            foreach ($definition['formats'] ?? [] as $range => $format) {
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode($format);
            }
            $cachedBySheet[$index] = $definition['cached'] ?? [];
            ++$index;
        }
        $spreadsheet->setActiveSheetIndex(0);
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $path = tempnam(sys_get_temp_dir(), 'attendance-fixture-');
        if ($path === false) {
            throw new \RuntimeException('Dočasný soubor pro fixture nejde vytvořit.');
        }
        try {
            $writer->save($path);
            $spreadsheet->disconnectWorksheets();
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw new \RuntimeException('Fixture XLSX nejde otevřít.');
            }
            foreach ($cachedBySheet as $sheetIndex => $cached) {
                if ($cached === []) {
                    continue;
                }
                $part = 'xl/worksheets/sheet' . ($sheetIndex + 1) . '.xml';
                $xml = $zip->getFromName($part);
                if (!is_string($xml)) {
                    throw new \RuntimeException("V fixture chybí {$part}.");
                }
                foreach ($cached as $coordinate => $value) {
                    [$type, $stored] = match (true) {
                        is_string($value) && str_starts_with($value, '#') => [' t="e"', $value],
                        is_string($value) => [' t="str"', htmlspecialchars($value, ENT_XML1)],
                        default => ['', (string) $value],
                    };
                    $count = 0;
                    $xml = (string) preg_replace_callback(
                        '#<c r="' . $coordinate . '"([^>]*)>(<f>.*?</f>)</c>#s',
                        static fn (array $m): string => '<c r="' . $coordinate . '"' . $m[1] . $type . '>'
                            . $m[2] . '<v>' . $stored . '</v></c>',
                        $xml,
                        1,
                        $count,
                    );
                    if ($count !== 1) {
                        throw new \RuntimeException("Vzorec {$coordinate} nebyl v XML listu nalezen.");
                    }
                }
                $zip->addFromString($part, $xml);
            }
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /** @return array{name:string,content:string,sha256:string,extension:string} */
    public static function file(string $name, string $content): array
    {
        return [
            'name' => $name,
            'content' => $content,
            'sha256' => hash('sha256', $content),
            'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
        ];
    }

    /**
     * Hlavní sešit, provozní sešit se dvěma listy a CSV mzdového exportu.
     *
     * @return list<array{name:string,content:string,sha256:string,extension:string}>
     */
    public static function scenario(int $janaBonus = 1500): array
    {
        return [
            self::file('podklady.xlsx', self::mainWorkbook($janaBonus)),
            self::file('provoz.xlsx', self::operationsWorkbook()),
            self::file('mzdy.csv', self::payrollCsv()),
        ];
    }

    /**
     * Hlavička až na řádku 2, pod daty řádek součtů a vpravo sloupec s kopií jmen.
     */
    public static function mainWorkbook(int $janaBonus = 1500): string
    {
        return self::xlsx([
            'Přehled' => [
                'rows' => [
                    1 => ['A' => 'Měsíční podklady'],
                    2 => [
                        'A' => 'Jméno a příjmení', 'B' => 'Oddělení', 'C' => 'Středisko', 'D' => 'Týdenní fond',
                        'E' => 'Název pozice', 'F' => 'Nástup/ukončení', 'G' => 'Odměna', 'H' => 'Srážky',
                        'I' => 'Jméno a příjmení',
                    ],
                    3 => [
                        'A' => 'Jana Testovací', 'B' => 'Výroba', 'C' => 'S100', 'D' => 40, 'E' => 'Operátorka',
                        'G' => $janaBonus, 'I' => 'Jana Testovací',
                    ],
                    4 => [
                        'A' => 'Petr Zkušební', 'B' => 'Sklad', 'C' => 'S200', 'D' => 40, 'E' => 'Skladník',
                        'G' => 2000, 'H' => 300, 'I' => 'Petr Zkušební',
                    ],
                    5 => [
                        'A' => 'Eva Pokusná', 'B' => 'Výroba', 'C' => 'S100', 'D' => 20, 'E' => 'Brigádnice',
                        'F' => 'nástup 1. 6. 2026', 'I' => 'Eva Pokusná',
                    ],
                    6 => ['A' => 'Celkem', 'G' => '=SUM(G3:G5)'],
                ],
                'cached' => ['G6' => 3500],
            ],
        ]);
    }

    /**
     * Vstupní list s hlavičkou zopakovanou na řádku 2 (trvání, i přes 24 h)
     * a výpočetní list s desetinnými hodinami, částkami a vzorci.
     */
    public static function operationsWorkbook(): string
    {
        return self::xlsx([
            'vstup' => [
                'rows' => [
                    1 => ['A' => 'Zaměstnanec', 'B' => 'Práce celkem', 'C' => 'Dovolená', 'D' => 'Nemoc', 'E' => 'Přesčas', 'F' => 'Kontrola'],
                    2 => ['A' => 'Zaměstnanec', 'B' => 'Práce celkem', 'C' => 'Dovolená', 'D' => 'Nemoc', 'E' => 'Přesčas', 'F' => 'Kontrola'],
                    3 => ['A' => 'Jana Testovací', 'B' => 7.0, 'C' => 0.6666666667, 'E' => 1.5, 'F' => 'Jana Testovací'],
                    4 => ['A' => 'Petr Zkušební', 'B' => 6.5, 'C' => 1.0, 'D' => 0.3333333333, 'F' => 'Petr Zkušební'],
                ],
                'formats' => ['B3:D4' => '[h]:mm', 'E3:E4' => 'd.m.yyyy h:mm'],
            ],
            'výpočet' => [
                'rows' => [
                    1 => ['A' => 'Jméno', 'B' => 'Dovolená', 'C' => 'Mzda úkol', 'D' => 'Příplatek noční', 'E' => 'Odměna'],
                    2 => ['A' => 'Jana Testovací', 'B' => 16, 'C' => '=B2*100', 'D' => '=B9*2', 'E' => '=B2*0+1500'],
                    3 => ['A' => 'Petr Zkušební', 'B' => 20, 'C' => '=B3*100', 'D' => '=#REF!*2', 'E' => '=B3*0+2500'],
                ],
                // C2: přepočet by dal 1600, uložená hodnota je 16800 — import musí vzít uloženou.
                'cached' => ['C2' => 16800, 'D2' => 1234.5, 'E2' => 1500, 'C3' => 2000, 'D3' => '#REF!', 'E3' => 2500],
            ],
        ]);
    }

    /** CSV mzdového exportu: čárka jako oddělovač, české částky v uvozovkách, Windows-1250. */
    public static function payrollCsv(): string
    {
        $lines = [
            'Jméno,Rodné číslo,Osobní číslo,Druh poměru,Hodiny,Hrubá mzda,Čistá mzda,Aktivní',
            '"Testovací Jana","' . self::janaBirthNumber() . '","Z001","Pracovní poměr","168,0","71 875,00 Kč","55 120,50 Kč",PRAVDA',
            '"Zkušební Petr","' . self::petrBirthNumber() . '","Z002","Pracovní poměr","156","48' . "\u{00A0}" . '000,00 Kč","37 000,00 Kč",PRAVDA',
        ];

        return self::toWindows1250(implode("\r\n", $lines) . "\r\n");
    }

    /** mbstring Windows-1250 nezná; převod jde přes tutéž tabulku, kterou čte import. */
    public static function toWindows1250(string $utf8): string
    {
        $reverse = array_flip(AttendanceCp1250::MAP);
        $result = '';
        foreach (mb_str_split($utf8, 1, 'UTF-8') as $char) {
            $codepoint = mb_ord($char, 'UTF-8');
            if ($codepoint < 0x80) {
                $result .= $char;
                continue;
            }
            if (!isset($reverse[$codepoint])) {
                throw new \InvalidArgumentException("Znak {$char} ve Windows-1250 není.");
            }
            $result .= chr($reverse[$codepoint]);
        }

        return $result;
    }

    public static function janaBirthNumber(): string
    {
        return self::birthNumber('1990-05-01', 'female', 1);
    }

    public static function petrBirthNumber(): string
    {
        return self::birthNumber('1985-03-12', 'male', 2);
    }

    /** Syntetické rodné číslo RRMMDD/XXXX dělitelné jedenácti; ženám se k měsíci přičítá 50. */
    public static function birthNumber(string $birthDate, string $sex, int $sequence): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $birthDate));
        $prefix = sprintf('%02d%02d%02d', $year % 100, $month + ($sex === 'female' ? 50 : 0), $day);
        for ($suffix = $sequence * 7; ; ++$suffix) {
            $nine = $prefix . sprintf('%03d', $suffix % 1000);
            for ($digit = 0; $digit <= 9; ++$digit) {
                if (((int) ($nine . $digit)) % 11 === 0) {
                    return $prefix . '/' . substr($nine . $digit, 6);
                }
            }
        }
    }
}
