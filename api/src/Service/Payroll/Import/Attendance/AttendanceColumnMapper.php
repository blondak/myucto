<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Přiřadí sloupcům listů význam: nejdřív zadaná pravidla (z požadavku nebo
 * profilu), pak automatický návrh. Návrh se vrací jako doplněná pravidla, takže
 * klient pošle při použití přesně to, co viděl v náhledu.
 *
 * @phpstan-import-type AttendanceRule from AttendanceRules
 * @phpstan-type AttendanceBinding array{
 *   column:int,letter:string,header:string,meaning:string,unit:?string,component_code:?string,
 *   rule_source:string,rule_index:?int,samples:list<string>,
 *   conditions:list<array{rule_index:int,when_header:string,when_column:?int,when_value:string,meaning:string,unit:?string,component_code:?string}>
 * }
 * @phpstan-type AttendanceMappedSheet array{
 *   sheet:AttendanceSheet,layout:AttendanceSheetLayout,columns:array<int,AttendanceBinding>,
 *   person_column:?int,data_rows:int
 * }
 */
final class AttendanceColumnMapper
{
    private const SAMPLES = 3;
    private const AUTO_COMPONENT_PREFIX = 'DOCH_';

    public function __construct(
        private readonly AttendanceSheetAnalyzer $analyzer,
        private readonly AttendanceRuleSuggester $suggester,
    ) {
    }

    /**
     * @param list<AttendanceSheet> $sheets
     * @param list<AttendanceRule> $rules
     * @return array{sheets:list<AttendanceMappedSheet>,rules:list<AttendanceRule>,auto_components:array<string,string>}
     */
    public function map(array $sheets, array $rules): array
    {
        $effective = $rules;
        $suggestedIndex = [];
        $mapped = [];
        $autoComponents = [];
        foreach ($sheets as $sheet) {
            $layout = $this->analyzer->analyze($sheet);
            $sheetKey = AttendanceText::normalize($sheet->name);
            $columns = [];
            $boundHeaders = [];
            foreach ($layout->headers as $column => $header) {
                $normalizedHeader = AttendanceText::normalize($header);
                /*
                 * Stejná hlavička podruhé v témž listu je jiný výpočet pod stejným
                 * jménem (typicky pomocný sloupec vedle skutečného). Platí první
                 * sloupec — jako všude, kde se tentýž údaj objeví víckrát —
                 * a druhý se do importu nebere.
                 */
                if (isset($boundHeaders[$normalizedHeader])) {
                    $columns[$column] = [
                        'column' => $column,
                        'letter' => Coordinate::stringFromColumnIndex($column),
                        'header' => $header,
                        'meaning' => AttendanceMeaning::IGNORE,
                        'unit' => null,
                        'component_code' => null,
                        'rule_source' => 'profile',
                        'rule_index' => null,
                        'samples' => $this->samples($sheet, $layout, $column, AttendanceMeaning::IGNORE),
                        'conditions' => [],
                    ];
                    continue;
                }
                $ruleIndex = null;
                $conditional = [];
                foreach ($rules as $index => $rule) {
                    if (!AttendanceRules::matches($rule, $sheetKey, $normalizedHeader)) {
                        continue;
                    }
                    // Pravidlo s podmínkou platí jen pro některé řádky; ostatní
                    // řádky sloupce dostanou první bezpodmínečné pravidlo.
                    if (AttendanceRules::hasCondition($rule)) {
                        $conditional[] = $index;
                        continue;
                    }
                    $ruleIndex = $index;
                    break;
                }
                $source = 'profile';
                if ($ruleIndex === null && $conditional === []) {
                    $key = $sheetKey . "\0" . $normalizedHeader;
                    if (isset($suggestedIndex[$key])) {
                        $ruleIndex = $suggestedIndex[$key];
                        $source = 'suggested';
                    } else {
                        $suggestion = $this->suggest($layout, $column, $header);
                        if ($suggestion['meaning'] !== AttendanceMeaning::IGNORE) {
                            $effective[] = [
                                'sheet' => $sheet->name,
                                'header' => $header,
                                'meaning' => $suggestion['meaning'],
                                'unit' => $this->defaultRuleUnit($suggestion['meaning']),
                                'component_code' => $suggestion['component_code'],
                            ];
                            $ruleIndex = array_key_last($effective);
                            $suggestedIndex[$key] = $ruleIndex;
                            $source = 'suggested';
                        } else {
                            $source = 'none';
                        }
                    }
                }
                $rule = $ruleIndex === null ? null : $effective[$ruleIndex];
                $meaning = $rule['meaning'] ?? AttendanceMeaning::IGNORE;
                $componentCode = $rule['component_code'] ?? null;
                if ($meaning === AttendanceMeaning::COMPONENT && $componentCode === AttendanceRules::AUTO_COMPONENT) {
                    $componentCode = $this->autoComponentCode($sheet, $layout, $column, $header);
                    if ($componentCode === null) {
                        $meaning = AttendanceMeaning::IGNORE;
                    } else {
                        $autoComponents[$componentCode] ??= mb_substr($header, 0, 190);
                    }
                }
                $columns[$column] = [
                    'column' => $column,
                    'letter' => Coordinate::stringFromColumnIndex($column),
                    'header' => $header,
                    'meaning' => $meaning,
                    'unit' => $meaning === AttendanceMeaning::IGNORE
                        ? null
                        : ($rule['unit'] ?? $this->columnUnit($sheet, $layout, $column, $meaning)),
                    'component_code' => $meaning === AttendanceMeaning::COMPONENT ? $componentCode : null,
                    'rule_source' => $source,
                    'rule_index' => $ruleIndex,
                    'samples' => $this->samples($sheet, $layout, $column, $meaning),
                    'conditions' => $this->conditions($sheet, $layout, $column, $rules, $conditional),
                ];
                if ($meaning !== AttendanceMeaning::IGNORE || $conditional !== []) {
                    $boundHeaders[$normalizedHeader] = true;
                }
            }
            $personColumn = null;
            foreach ($columns as $column => $binding) {
                if ($binding['meaning'] === AttendanceMeaning::PERSON_NAME) {
                    $personColumn = $column;
                    break;
                }
            }
            $mapped[] = [
                'sheet' => $sheet,
                'layout' => $layout,
                'columns' => $columns,
                'person_column' => $personColumn,
                'data_rows' => $personColumn === null ? 0 : count($this->personRows($sheet, $layout, $personColumn)),
            ];
        }

        return ['sheets' => $mapped, 'rules' => $effective, 'auto_components' => $autoComponents];
    }

    /**
     * Pravidla s podmínkou pro sloupec, s dohledaným sloupcem podmínky
     * v témž listu. Chybí-li sloupec podmínky, podmínka nikdy neplatí.
     *
     * @param list<AttendanceRule> $rules
     * @param list<int> $indexes
     * @return list<array{rule_index:int,when_header:string,when_column:?int,when_value:string,meaning:string,unit:?string,component_code:?string}>
     */
    private function conditions(
        AttendanceSheet $sheet,
        AttendanceSheetLayout $layout,
        int $column,
        array $rules,
        array $indexes,
    ): array {
        $result = [];
        foreach ($indexes as $index) {
            $rule = $rules[$index];
            $whenHeader = (string) ($rule['when_header'] ?? '');
            $whenColumn = null;
            foreach ($layout->headers as $candidate => $header) {
                if (AttendanceRules::like($whenHeader, AttendanceText::normalize($header))) {
                    $whenColumn = $candidate;
                    break;
                }
            }
            $meaning = $rule['meaning'];
            $result[] = [
                'rule_index' => $index,
                'when_header' => $whenHeader,
                'when_column' => $whenColumn,
                'when_value' => (string) ($rule['when_value'] ?? ''),
                'meaning' => $meaning,
                'unit' => $meaning === AttendanceMeaning::IGNORE
                    ? null
                    : ($rule['unit'] ?? $this->columnUnit($sheet, $layout, $column, $meaning)),
                'component_code' => $meaning === AttendanceMeaning::COMPONENT ? $rule['component_code'] : null,
            ];
        }

        return $result;
    }

    /**
     * Řádky s osobou: od začátku dat, se jménem, bez řádku součtů a bez
     * opakované hlavičky.
     *
     * @return array<int,string> řádek → jméno
     */
    public function personRows(AttendanceSheet $sheet, AttendanceSheetLayout $layout, int $personColumn): array
    {
        $headerName = AttendanceText::normalize($layout->headers[$personColumn] ?? '');
        $rows = [];
        for ($row = $layout->dataStartRow; $row <= $sheet->maxRow; ++$row) {
            $cell = $sheet->cell($row, $personColumn);
            if ($cell->kind !== AttendanceCell::STRING) {
                continue;
            }
            $name = trim((string) preg_replace('/\s+/u', ' ', $cell->textValue()));
            if ($name === ''
                || preg_match('/\p{L}/u', $name) !== 1
                || AttendanceText::isTotalsLabel($name)
                || AttendanceText::normalize($name) === $headerName
                || AttendanceText::personKey($name) === '') {
                continue;
            }
            $rows[$row] = $name;
        }

        return $rows;
    }

    /**
     * Kód složky pro sloupec „podle hlavičky". Jen sloupec, který opravdu nese
     * částky: hlavička s aspoň třemi písmeny (ne sazba „140" ani výplňové „x"),
     * převažují čísla a nejsou to trvání. Sloupec se jmény nebo hodinami tak
     * nikdy nevyrobí mzdovou složku.
     */
    private function autoComponentCode(
        AttendanceSheet $sheet,
        AttendanceSheetLayout $layout,
        int $column,
        string $header,
    ): ?string {
        if ($header === AttendanceSheetAnalyzer::PERSON_PLACEHOLDER
            || preg_match_all('/\p{L}/u', $header) < 3) {
            return null;
        }
        $numbers = 0;
        $durations = 0;
        $texts = 0;
        for ($row = $layout->dataStartRow; $row <= $sheet->maxRow; ++$row) {
            $cell = $sheet->cell($row, $column);
            if ($cell->kind === AttendanceCell::NUMBER) {
                ++$numbers;
                if ($cell->hasDurationFormat()) {
                    ++$durations;
                }
            } elseif ($cell->kind === AttendanceCell::STRING) {
                ++$texts;
            }
        }
        // Sloupec s jakýmkoli textem jsou kopie jmen nebo poznámky, ne částky
        // (prázdné řádky takových kopií vracejí ze vzorce nulu).
        if ($numbers === 0 || $texts > 0 || $durations * 2 > $numbers) {
            return null;
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', AttendanceText::normalize($header)), '_');
        if ($slug === '') {
            return null;
        }

        return rtrim(substr(self::AUTO_COMPONENT_PREFIX . strtoupper($slug), 0, 64), '_');
    }

    /** @return array{meaning:string,component_code:?string} */
    private function suggest(AttendanceSheetLayout $layout, int $column, string $header): array
    {
        $suggestion = $this->suggester->suggest($header);
        // Osoba vzniká jen z JEDNOHO sloupce listu. Další sloupce se jménem
        // (kopie vpravo pro vyhledávání v sešitu) by jinak vyrobily osoby znovu.
        if ($suggestion['meaning'] === AttendanceMeaning::PERSON_NAME && $column !== $layout->suggestedPersonColumn) {
            return ['meaning' => AttendanceMeaning::IGNORE, 'component_code' => null];
        }
        if ($column === $layout->suggestedPersonColumn && $suggestion['meaning'] === AttendanceMeaning::IGNORE) {
            return ['meaning' => AttendanceMeaning::PERSON_NAME, 'component_code' => null];
        }

        return $suggestion;
    }

    private function defaultRuleUnit(string $meaning): ?string
    {
        if (AttendanceMeaning::isHours($meaning)) {
            return null;
        }

        return AttendanceMeaning::allowedUnits($meaning)[0];
    }

    private function columnUnit(AttendanceSheet $sheet, AttendanceSheetLayout $layout, int $column, string $meaning): ?string
    {
        if ($meaning === AttendanceMeaning::IGNORE) {
            return null;
        }
        if (AttendanceMeaning::isHours($meaning)) {
            return $this->analyzer->suggestHoursUnit($sheet, $layout, $column);
        }

        return AttendanceMeaning::allowedUnits($meaning)[0];
    }

    /** @return list<string> */
    private function samples(AttendanceSheet $sheet, AttendanceSheetLayout $layout, int $column, string $meaning): array
    {
        $samples = [];
        for ($row = $layout->dataStartRow; $row <= $sheet->maxRow && count($samples) < self::SAMPLES; ++$row) {
            $cell = $sheet->cell($row, $column);
            if ($cell->isEmpty()) {
                continue;
            }
            $text = $cell->display();
            // Rodné číslo do náhledu nepatří ani tehdy, když sloupec zatím nikdo nenamapoval.
            if ($meaning === 'birth_number' || preg_match('/^\d{6}\s*\/?\s*\d{3,4}$/', $text) === 1) {
                $text = '••••••/••••';
            } elseif ($meaning === 'birth_date') {
                $text = '••. ••. ••••';
            }
            $samples[] = $text;
        }

        return $samples;
    }
}
