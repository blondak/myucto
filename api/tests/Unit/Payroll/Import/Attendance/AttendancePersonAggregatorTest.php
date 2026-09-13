<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceColumnMapper;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePersonAggregator;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRules;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRuleSuggester;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSheetAnalyzer;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceWorkbookReader;
use PHPUnit\Framework\TestCase;

/**
 * Celá cesta bez databáze: soubory → mapování sloupců → osoby s hodnotami.
 */
final class AttendancePersonAggregatorTest extends TestCase
{
    public function testPersonsComeOnlyFromThePersonColumnAndMergeAcrossFiles(): void
    {
        $result = $this->pipeline(AttendanceFixture::scenario());
        $persons = $this->byKey($result['persons']);

        // Kopie jmen vpravo, řádek součtů ani zopakovaná hlavička osoby nevyrobí.
        self::assertSame(['eva pokusna', 'jana testovaci', 'petr zkusebni'], array_keys($persons));
        $jana = $persons['jana testovaci'];
        self::assertSame('Jana Testovací', $jana['display_name']);
        self::assertSame('Z001', $jana['personal_number']);
        self::assertSame(AttendanceFixture::janaBirthNumber(), $jana['_birth_number']);
        self::assertContains(['sheet_id' => 'mzdy.csv#CSV', 'row' => 2], $jana['sources']);
        self::assertSame('Výroba', $jana['department']);
        self::assertSame('Pracovní poměr', $jana['relation_label']);
        self::assertSame('20', $persons['eva pokusna']['weekly_hours']);
        self::assertSame('nástup 1. 6. 2026', $persons['eva pokusna']['start_end_note']);

        $sheets = [];
        foreach ($result['sheets'] as $sheet) {
            $sheets[$sheet['sheet']->id()] = $sheet;
        }
        self::assertSame(2, $sheets['podklady.xlsx#Přehled']['layout']->headerRow);
        self::assertSame(3, $sheets['podklady.xlsx#Přehled']['data_rows']);
        self::assertSame(2, $sheets['provoz.xlsx#vstup']['data_rows']);
        self::assertSame(1, $sheets['podklady.xlsx#Přehled']['person_column']);
    }

    public function testHoursDurationsAndEmptyCells(): void
    {
        $persons = $this->byKey($this->pipeline(AttendanceFixture::scenario())['persons']);
        $jana = $this->metrics($persons['jana testovaci']);
        $petr = $this->metrics($persons['petr zkusebni']);

        self::assertSame('168.00', $jana['worked_hours']['hours']);
        self::assertSame('16.00', $jana['vacation_hours']['hours']);
        self::assertSame([], $jana['vacation_hours']['conflicts'], 'Stejná hodnota ze dvou listů není konflikt.');
        self::assertSame('36.00', $jana['overtime_hours']['hours']);
        self::assertArrayNotHasKey('sick_hours', $jana, 'Prázdná buňka není nula.');
        self::assertSame('8.00', $petr['sick_hours']['hours']);
        self::assertSame('provoz.xlsx!vstup!B3', $jana['worked_hours']['source']);
        self::assertSame('168.00', $persons['jana testovaci']['reference']['hours']);
        self::assertSame(7187500, $persons['jana testovaci']['reference']['gross_minor']);
        self::assertSame(5512050, $persons['jana testovaci']['reference']['net_minor']);
    }

    /**
     * Dovolená je ve vstupním listu (24 h) i ve výpočtu (20 h). Nesčítá se:
     * platí první podle pořadí pravidel a druhá hodnota je konflikt s varováním.
     */
    public function testSameMeaningFromTwoSheetsIsNeverSummed(): void
    {
        $petr = $this->byKey($this->pipeline(AttendanceFixture::scenario())['persons'])['petr zkusebni'];
        $vacation = $this->metrics($petr)['vacation_hours'];

        self::assertSame('24.00', $vacation['hours']);
        self::assertSame([['hours' => '20.00', 'source' => 'provoz.xlsx!výpočet!B3']], $vacation['conflicts']);
        self::assertNotEmpty(array_filter($petr['warnings'], static fn (string $w): bool => str_contains($w, 'nesčítají')));
    }

    public function testRuleOrderDecidesWhichValueWins(): void
    {
        $rules = AttendanceRules::validate([
            ['sheet' => 'výpočet', 'header' => 'Dovolená', 'meaning' => 'vacation_hours', 'unit' => 'hours', 'component_code' => null],
        ]);
        $petr = $this->byKey($this->pipeline(AttendanceFixture::scenario(), $rules)['persons'])['petr zkusebni'];
        $vacation = $this->metrics($petr)['vacation_hours'];

        self::assertSame('20.00', $vacation['hours']);
        self::assertSame('24.00', $vacation['conflicts'][0]['hours']);
    }

    public function testComponentsUseStoredFormulaValuesAndReportErrors(): void
    {
        $persons = $this->byKey($this->pipeline(AttendanceFixture::scenario())['persons']);
        $jana = $this->components($persons['jana testovaci']);
        $petr = $this->components($persons['petr zkusebni']);

        self::assertSame(150000, $jana['ODMENA']['amount_minor']);
        self::assertSame([], $jana['ODMENA']['conflicts']);
        self::assertSame(1680000, $jana['MZDA_UKOLOVA']['amount_minor']);
        self::assertSame(123450, $jana['PREMIE_PRIPLATKY']['amount_minor']);
        self::assertSame(200000, $petr['ODMENA']['amount_minor']);
        self::assertSame(250000, $petr['ODMENA']['conflicts'][0]['amount_minor']);
        self::assertArrayNotHasKey('PREMIE_PRIPLATKY', $petr, '#REF! není nula ani částka.');
        self::assertNotEmpty(array_filter(
            $persons['petr zkusebni']['warnings'],
            static fn (string $w): bool => str_contains($w, '#REF!') && str_contains($w, 'výpočet!D3'),
        ));
        self::assertArrayNotHasKey('ODMENA', $this->components($persons['eva pokusna']));
    }

    public function testSameNameWithDifferentPersonalNumbersIsNotMerged(): void
    {
        $csv = "Jméno;Osobní číslo;Dovolená\nJan Novák;A1;8\nNovák Jan;A2;16\n";
        $persons = $this->byKey($this->pipeline([AttendanceFixture::file('a.csv', $csv)])['persons']);

        self::assertSame(['jan novak#A1', 'jan novak#A2'], array_keys($persons));
        self::assertSame('8.00', $this->metrics($persons['jan novak#A1'])['vacation_hours']['hours']);
        self::assertNotEmpty($persons['jan novak#A2']['warnings']);
    }

    public function testDuplicateRowOfOnePersonInOneSheetIsAConflictNotASum(): void
    {
        $csv = "Jméno;Dovolená\nJan Novák;8\nIng. Jan Novák;16\n";
        $persons = $this->byKey($this->pipeline([AttendanceFixture::file('a.csv', $csv)])['persons']);
        $vacation = $this->metrics($persons['jan novak'])['vacation_hours'];

        self::assertCount(1, $persons);
        self::assertSame('8.00', $vacation['hours']);
        self::assertSame('16.00', $vacation['conflicts'][0]['hours']);
        self::assertNotEmpty(array_filter($persons['jan novak']['warnings'], static fn (string $w): bool => str_contains($w, 'vícekrát')));
    }

    public function testSuggestedRulesAreReturnedAndCopiesOfNamesAreIgnored(): void
    {
        $result = $this->pipeline(AttendanceFixture::scenario());
        $vstup = null;
        foreach ($result['sheets'] as $sheet) {
            if ($sheet['sheet']->id() === 'provoz.xlsx#vstup') {
                $vstup = $sheet;
            }
        }
        self::assertNotNull($vstup);
        self::assertSame('person_name', $vstup['columns'][1]['meaning']);
        self::assertSame('ignore', $vstup['columns'][6]['meaning']);
        self::assertSame('none', $vstup['columns'][6]['rule_source']);
        self::assertSame('excel_duration', $vstup['columns'][3]['unit']);
        self::assertSame(['16:00', '24:00'], $vstup['columns'][3]['samples']);

        $meanings = array_map(static fn (array $rule): string => $rule['meaning'], $result['rules']);
        self::assertContains('vacation_hours', $meanings);
        self::assertNotContains('ignore', $meanings);
    }

    /**
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @param list<array<string,mixed>> $rules
     * @return array{sheets:list<array<string,mixed>>,rules:list<array<string,mixed>>,persons:list<array<string,mixed>>}
     */
    private function pipeline(array $files, array $rules = []): array
    {
        $reader = new AttendanceWorkbookReader();
        $sheets = [];
        foreach ($files as $index => $file) {
            array_push($sheets, ...$reader->read($file['name'], $index, $file['extension'], $file['content']));
        }
        $suggester = new AttendanceRuleSuggester();
        $mapper = new AttendanceColumnMapper(new AttendanceSheetAnalyzer($suggester), $suggester);
        /** @var list<array{sheet:?string,header:string,meaning:string,unit:?string,component_code:?string}> $rules */
        $mapped = $mapper->map($sheets, $rules);

        return $mapped + ['persons' => (new AttendancePersonAggregator($mapper))->aggregate($mapped['sheets'])];
    }

    /**
     * @param list<array<string,mixed>> $persons
     * @return array<string,array<string,mixed>>
     */
    private function byKey(array $persons): array
    {
        $result = [];
        foreach ($persons as $person) {
            $result[$person['key']] = $person;
        }
        ksort($result);

        return $result;
    }

    /**
     * @param array<string,mixed> $person
     * @return array<string,array<string,mixed>>
     */
    private function metrics(array $person): array
    {
        return array_column($person['metrics'], null, 'meaning');
    }

    /**
     * @param array<string,mixed> $person
     * @return array<string,array<string,mixed>>
     */
    private function components(array $person): array
    {
        return array_column($person['components'], null, 'component_code');
    }
}
