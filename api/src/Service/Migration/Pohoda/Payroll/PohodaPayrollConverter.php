<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceText;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Mzdy z datového souboru POHODA Mzdy / PAMICA (`91_mzdy.xml`, vytváří ho exportní
 * nástroj `tools/pohoda-export/Export-PohodaMdb.ps1`) jako měsíční sešity pro import
 * docházky a mezd MyÚčta ({@see \MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService}).
 *
 * Řádek sešitu = pracovní poměr v měsíci (tabulka `MZ`): identita osoby a vztahu
 * (`ZAM`, `ZAMpomer`), fond a odpracované hodiny, nepřítomnosti v hodinách (`MZneprit`),
 * mzdové složky v Kč (`MZslozky`), srážky (`MZsrazky`) a kontrolní hrubá a čistá mzda.
 * Význam sloupců nese profil importu ({@see self::profile()}); import pak jde stejnou
 * cestou jako ruční import v Mzdy → Importy.
 *
 * Druhý souběžný poměr téže osoby dostane osobní číslo s pořadím (`1001-2`).
 */
final class PohodaPayrollConverter
{
    public const SHEET = 'mzdy-pohoda';
    public const PROFILE_NAME = 'POHODA mzdy (převod)';

    /** Identita a vztah; každý měsíc má tyto sloupce ve stejném pořadí. */
    private const BASE_COLUMNS = [
        ['Zaměstnanec', 'person_name', 'text'],
        ['Osobní číslo', 'personal_number', 'text'],
        ['Rodné číslo', 'birth_number', 'text'],
        ['Datum narození', 'birth_date', 'text'],
        ['Zdravotní pojišťovna', 'health_insurer_code', 'text'],
        ['Druh vztahu', 'relation_label', 'text'],
        ['Středisko', 'cost_center', 'text'],
        ['Pracovní místo', 'position', 'text'],
        ['Týdenní úvazek', 'weekly_hours', 'text'],
        ['Měsíční mzda', 'monthly_wage', 'text'],
        ['Nástup / ukončení', 'start_end_note', 'text'],
        ['Příjmení', 'ignore', null],
        ['Jméno', 'ignore', null],
        ['Fond pracovní doby (h)', 'fund_hours', 'hours'],
        ['Odpracováno (h)', 'worked_hours', 'hours'],
    ];

    /** @var array<string,array<string,array<string,mixed>>> tabulka => ID => řádek (číselníky, osoby, vztahy) */
    private array $byId = [];
    /** @var array<string,list<array<string,mixed>>> období `Y-m` => řádky MZ */
    private array $mz = [];
    /** @var array<string,array<string,list<array<string,mixed>>>> tabulka => ID mzdy => řádky položek */
    private array $items = [];
    /** @var array<string,int> ID osoby => počet vztahů */
    private array $relationCount = [];
    /** @var array<string,int> číslo složky => počet složek katalogu s tím číslem */
    private array $numberUse = [];

    private function __construct(public readonly string $ico) {}

    public static function read(string $file): self
    {
        if (!is_file($file)) {
            throw new PohodaException('payroll_missing', 'Export neobsahuje mzdy (91_mzdy.xml).');
        }
        $info = PohodaXml::packInfo($file);
        $self = new self(preg_replace('/\D/', '', $info['ico']) ?? '');
        foreach (['sMZslozky', 'sMZneprit', 'sMZsrazky', 'sMzPoj', 'sSTR', 'PracMista', 'ZAM', 'ZAMpomer'] as $table) {
            foreach (PohodaXml::records($file, $table) as $row) {
                $self->byId[$table][PohodaXml::text($row, 'ID')] = $row;
            }
        }
        foreach ($self->byId['ZAMpomer'] ?? [] as $relation) {
            $person = PohodaXml::text($relation, 'RefZAM');
            $self->relationCount[$person] = ($self->relationCount[$person] ?? 0) + 1;
        }
        foreach ($self->byId['sMZslozky'] ?? [] as $row) {
            $number = strtoupper(PohodaXml::text($row, 'Cislo'));
            $self->numberUse[$number] = ($self->numberUse[$number] ?? 0) + 1;
        }
        foreach (PohodaXml::records($file, 'MZ') as $row) {
            $period = sprintf('%04d-%02d', (int) PohodaXml::text($row, 'Rok'), (int) PohodaXml::text($row, 'RelMes'));
            $self->mz[$period][] = $row;
        }
        ksort($self->mz);
        foreach (['MZslozky', 'MZneprit', 'MZsrazky'] as $table) {
            foreach (PohodaXml::records($file, $table) as $row) {
                $self->items[$table][PohodaXml::text($row, 'RefAg')][] = $row;
            }
        }
        return $self;
    }

    /** @return list<string> období `Y-m`, pro která jsou zpracované mzdy */
    public function periods(?int $year = null): array
    {
        return array_values(array_filter(array_keys($this->mz), static fn (string $p): bool => $year === null || str_starts_with($p, $year . '-')));
    }

    public function employees(): int
    {
        return count($this->byId['ZAM'] ?? []);
    }

    /**
     * Sešit jednoho měsíce: sloupce (hlavička => význam), řádky a součty ke kontrole.
     *
     * `unclassified_deductions` nese druhy srážek, které se do sešitu nedostaly, protože
     * jim v exportu chybí řádek číselníku `sMZsrazky`; zahodit je tiše nelze (mohla by to
     * být exekuce), takže je převod předá protokolu k ručnímu dořešení.
     *
     * @return array{period:string, columns:array<string,array{meaning:string,unit:?string,kind:?string,code:?string}>,
     *     rows:list<array<string,string|float|null>>, totals:array<string,int>, omitted:list<string>,
     *     unclassified_deductions:array<string,array{code:string,name:string,inputs:int}>}
     */
    public function month(string $period): array
    {
        $columns = [];
        $column = static function (string $header, string $meaning, ?string $unit, ?string $kind = null, ?string $code = null) use (&$columns): void {
            $columns[$header] ??= ['meaning' => $meaning, 'unit' => $unit, 'kind' => $kind, 'code' => $code];
        };
        foreach (self::BASE_COLUMNS as [$header, $meaning, $unit]) {
            $column($header, $meaning, $unit);
        }

        $rows = [];
        $omitted = [];
        /** @var array<string,array{code:string,name:string,inputs:int}> $unclassifiedDeductions */
        $unclassifiedDeductions = [];
        $totals = ['rows' => 0, 'gross_minor' => 0, 'net_minor' => 0, 'components_minor' => 0, 'meal_minor' => 0, 'deduction_minor' => 0, 'worked_millihours' => 0];
        foreach ($this->mz[$period] ?? [] as $mz) {
            $mzId = PohodaXml::text($mz, 'ID');
            $person = $this->byId['ZAM'][PohodaXml::text($mz, 'RefZAM')] ?? null;
            $relation = $this->byId['ZAMpomer'][PohodaXml::text($mz, 'RefPomer')] ?? null;
            if ($person === null || $relation === null) {
                throw new PohodaException('payroll_inconsistent', "Mzda za {$period} nemá v exportu zaměstnance nebo pracovní poměr.");
            }
            $values = [];
            $add = static function (string $header, float $value) use (&$values): void {
                $values[$header] = ($values[$header] ?? 0.0) + $value;
            };
            $monthlyWage = null;
            // PAMICA vede odpracované hodiny (`HodOdpra`) bez přesčasu; import MyÚčta
            // (docházka, pracovní měsíc, JMHZ) čeká odpracováno včetně přesčasu.
            $overtime = 0.0;
            foreach ($this->items['MZslozky'][$mzId] ?? [] as $item) {
                $catalog = $this->byId['sMZslozky'][PohodaXml::text($item, 'RefSlozka')] ?? null;
                $number = strtoupper(PohodaXml::text($catalog ?? [], 'Cislo'));
                $class = PohodaPayrollCatalog::component($number, PohodaXml::text($catalog ?? [], 'Nazev'), ($this->numberUse[$number] ?? 0) > 1);
                $amount = PohodaXml::num($item, 'KcMzda');
                if ($class['meaning'] === 'meal') {
                    $column($class['header'], 'net_meal_deduction', 'amount');
                    $add($class['header'], -$amount);
                    $totals['meal_minor'] += self::minor(-$amount);
                } elseif ($class['meaning'] === 'component') {
                    $column($class['header'], 'component', 'amount', $class['kind'], $class['code']);
                    $add($class['header'], $amount);
                    $totals['components_minor'] += self::minor($amount);
                }
                $hours = PohodaPayrollCatalog::workHours($number);
                if ($hours !== null) {
                    $column($hours['header'], $hours['meaning'], 'hours');
                    $add($hours['header'], PohodaXml::num($item, 'PocHodin'));
                    if ($hours['meaning'] === 'overtime_hours') {
                        $overtime += PohodaXml::num($item, 'PocHodin');
                    }
                }
                if (in_array($number, ['M01', 'M09'], true) && PohodaXml::num($item, 'Hodnota1') > 0) {
                    $monthlyWage = max($monthlyWage ?? 0.0, PohodaXml::num($item, 'Hodnota1'));
                }
            }
            /*
             * Nepřítomnost, kterou evidence vede jedině s daty od a do (nemoc, ošetřovné,
             * otcovská, neplacené volno, neomluvená absence), do měsíčního sešitu NEPATŘÍ:
             * tutéž dobu zapíše převod datovaně z `MZneprit` a jeden údaj má mít jediný
             * zdroj - {@see PohodaPayrollCatalog::absenceNeedsDates()}. Z holého měsíčního
             * součtu hodin se náhrada mzdy ani vyloučená doba spočítat nedá, takže souhrn
             * s nimi měsíc stejně schválit nepustí.
             *
             * Vypouští se jen druh, u kterého má data KAŽDÝ jeho řádek v měsíci. Kdyby se
             * vypustila jen datovaná část, zbytek by v souhrnu druh podržel, převod by kvůli
             * tomu nezapsal ani datovanou část ({@see \MyInvoice\Service\Payroll\Migration\PayrollTakeoverAbsenceWriter::absences()})
             * a ta doba by zmizela z obou stran.
             */
            $year = (int) substr($period, 0, 4);
            /** @var array<string,list<array{header:string,hours:float}>> $absenceHours význam => hodiny řádků */
            $absenceHours = [];
            /** @var array<string,bool> $absenceDated význam => zapíše se datovaně místo do sešitu */
            $absenceDated = [];
            foreach ($this->items['MZneprit'][$mzId] ?? [] as $item) {
                $catalog = $this->byId['sMZneprit'][PohodaXml::text($item, 'RefSlozka')] ?? [];
                $number = PohodaXml::text($catalog, 'Cislo');
                $name = PohodaXml::text($catalog, 'Nazev');
                $class = PohodaPayrollCatalog::absence($number, $name);
                if ($class['meaning'] === 'ignore') {
                    continue;
                }
                $meaning = $class['meaning'];
                $absenceHours[$meaning][] = ['header' => $class['header'], 'hours' => PohodaXml::num($item, 'HodPrac')];
                $absenceDated[$meaning] = ($absenceDated[$meaning] ?? true)
                    && PohodaPayrollCatalog::absenceNeedsDates($number, $name)
                    && PohodaPayrollPeople::absenceDates($item, $year) !== null;
            }
            foreach ($absenceHours as $meaning => $items) {
                if ($absenceDated[$meaning] === true) {
                    continue;
                }
                foreach ($items as $absence) {
                    $column($absence['header'], $meaning, 'hours');
                    $add($absence['header'], $absence['hours']);
                }
            }
            foreach ($this->items['MZsrazky'][$mzId] ?? [] as $item) {
                $reference = PohodaXml::text($item, 'RefSlozka');
                // Zákonná srážka (`ignore`) do sešitu nepatří: exekuční případ z ní dělá
                // samostatný krok převodu, takže by se z čisté mzdy strhla dvakrát.
                $class = PohodaPayrollCatalog::deduction($this->byId['sMZsrazky'][$reference] ?? []);
                $amount = PohodaXml::num($item, 'KcSrazeno');
                if ($class['meaning'] === 'unclassified') {
                    if (round($amount, 2) != 0.0) {
                        $key = $class['code'] !== '' ? $class['code'] : '#' . $reference;
                        $unclassifiedDeductions[$key] ??= ['code' => $key, 'name' => $class['name'], 'inputs' => 0];
                        $unclassifiedDeductions[$key]['inputs']++;
                    }
                    continue;
                }
                if ($class['meaning'] === 'ignore') {
                    continue;
                }
                $column($class['header'], $class['meaning'], 'amount');
                $add($class['header'], $amount);
                $totals[$class['meaning'] === 'net_meal_deduction' ? 'meal_minor' : 'deduction_minor'] += self::minor($amount);
            }

            $isDpp = self::bool(PohodaXml::text($relation, 'JeDPP'));
            $weekly = PohodaXml::num($mz, 'TUvazek') > 0 ? PohodaXml::num($mz, 'TUvazek') : PohodaXml::num($relation, 'TUvazek');
            // `HodFond` je fond plného úvazku (denní úvazek × pracovní dny) i u kratšího
            // týdenního úvazku; fond vztahu je pracovní dny fondu × týdenní úvazek / 5.
            $fundDays = PohodaXml::num($mz, 'DnyFond2');
            $fund = $fundDays > 0 && $weekly > 0 && !$isDpp ? $fundDays * $weekly / 5 : PohodaXml::num($mz, 'HodFond');
            $worked = PohodaXml::num($mz, 'HodOdpra') + $overtime;
            $insurer = $this->byId['sMzPoj'][PohodaXml::text($mz, 'RefPoj')] ?? $this->byId['sMzPoj'][PohodaXml::text($person, 'RefPoj')] ?? [];
            $center = $this->byId['sSTR'][PohodaXml::text($relation, 'ResStr')] ?? [];
            $place = $this->byId['PracMista'][PohodaXml::text($relation, 'RelPracMist')] ?? [];
            $start = self::date(PohodaXml::text($relation, 'DatNast')) ?? self::date(PohodaXml::text($relation, 'DatVstup'));
            $end = self::date(PohodaXml::text($relation, 'DatOdch'));
            $personalNumber = $this->personalNumber($person, $relation);
            // Údaj, který by osobu nešlo založit (rodné číslo bez platného data narození,
            // pojišťovna mimo číselník - typicky cizinci), se vynechá a převod ho vypíše k doplnění.
            $birthNumber = PohodaXml::text($person, 'RodCisl');
            if ($birthNumber !== '') {
                try {
                    CzechBirthNumber::normalize($birthNumber);
                } catch (\InvalidArgumentException $e) {
                    $omitted[] = "osobní číslo {$personalNumber}: rodné číslo z POHODY je neplatné ({$e->getMessage()}), vynecháno - doplňte ho v evidenci zaměstnance.";
                    $birthNumber = '';
                }
            }
            $insurerCode = PohodaXml::text($insurer, 'Kod');
            if ($insurerCode !== '' && !HealthInsurers::isValid($insurerCode)) {
                $omitted[] = "osobní číslo {$personalNumber}: kód zdravotní pojišťovny {$insurerCode} není v číselníku, vynechán - doplňte ho v evidenci zaměstnance.";
                $insurerCode = '';
            }
            $row = [
                'Zaměstnanec' => trim(PohodaXml::text($person, 'Prijmeni') . ' ' . PohodaXml::text($person, 'Jmeno')),
                'Osobní číslo' => $personalNumber,
                'Rodné číslo' => $birthNumber,
                'Datum narození' => self::czechDate(self::date(PohodaXml::text($person, 'DatNar'))),
                'Zdravotní pojišťovna' => $insurerCode,
                'Druh vztahu' => $isDpp ? 'DPP' : 'pracovní poměr',
                'Středisko' => PohodaXml::text($center, 'IDS'),
                'Pracovní místo' => PohodaXml::text($place, 'SText') ?: PohodaXml::text($place, 'IDS'),
                'Týdenní úvazek' => $weekly > 0 && !$isDpp ? round($weekly, 2) : null,
                'Měsíční mzda' => $monthlyWage !== null ? round($monthlyWage, 2) : null,
                'Nástup / ukončení' => trim(($start !== null ? 'nástup ' . self::czechDate($start) : '') . ($end !== null ? ', ukončení ' . self::czechDate($end) : ''), ', '),
                'Příjmení' => PohodaXml::text($person, 'Prijmeni'),
                'Jméno' => PohodaXml::text($person, 'Jmeno'),
                'Fond pracovní doby (h)' => round($fund, 2),
                'Odpracováno (h)' => round($worked, 2),
                'Hrubá mzda (Kč)' => round(PohodaXml::num($mz, 'KcHrubaM'), 2),
                'Čistá mzda (Kč)' => round(PohodaXml::num($mz, 'KcCistaM'), 2),
                '_start' => $start,
            ];
            foreach ($values as $header => $value) {
                if (round($value, 2) != 0.0) {
                    $row[$header] = round($value, 2);
                }
            }
            $rows[] = $row;
            $totals['rows']++;
            $totals['gross_minor'] += self::minor(PohodaXml::num($mz, 'KcHrubaM'));
            $totals['net_minor'] += self::minor(PohodaXml::num($mz, 'KcCistaM'));
            $totals['worked_millihours'] += (int) round($worked * 1000);
        }
        $column('Hrubá mzda (Kč)', 'reference_gross', 'amount');
        $column('Čistá mzda (Kč)', 'reference_net', 'amount');
        usort($rows, static fn (array $a, array $b): int => [AttendanceText::normalize((string) $a['Zaměstnanec']), $a['Osobní číslo']]
            <=> [AttendanceText::normalize((string) $b['Zaměstnanec']), $b['Osobní číslo']]);

        uasort($unclassifiedDeductions, static fn (array $a, array $b): int => [$b['inputs'], $a['code']] <=> [$a['inputs'], $b['code']]);

        return ['period' => $period, 'columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'omitted' => $omitted,
            'unclassified_deductions' => $unclassifiedDeductions];
    }

    /**
     * Profil importu nad sloupci všech měsíců: přesná hlavička → význam, zbytek listu se
     * ignoruje; mzdové složky, které ve firmě chybí, import založí podle `components`.
     *
     * @param list<array{columns:array<string,array{meaning:string,unit:?string,kind:?string,code:?string}>}> $months
     * @return array{name:string, rules:list<array<string,mixed>>, components:list<array{code:string,name:string,kind:string}>}
     */
    public static function profile(array $months): array
    {
        $columns = [];
        foreach ($months as $month) {
            $columns += $month['columns'];
        }
        $rules = [];
        $components = [];
        foreach ($columns as $header => $column) {
            $rules[] = ['sheet' => self::SHEET, 'header' => $header, 'meaning' => $column['meaning'], 'unit' => $column['unit'], 'component_code' => $column['code']];
            if ($column['meaning'] === 'component' && $column['code'] !== null) {
                $components[$column['code']] = [
                    'code' => $column['code'],
                    'name' => mb_substr((string) preg_replace('/\s*\(Kč\)$/u', '', $header), 0, 120),
                    'kind' => (string) $column['kind'],
                ];
            }
        }
        $rules[] = ['sheet' => self::SHEET, 'header' => '*', 'meaning' => 'ignore', 'unit' => null, 'component_code' => null];
        return ['name' => self::PROFILE_NAME, 'rules' => $rules, 'components' => array_values($components)];
    }

    /**
     * Sešit měsíce jako soubor pro import (stejný tvar, jaký dává nahrání souboru).
     *
     * @param array{period:string, columns:array<string,mixed>, rows:list<array<string,mixed>>} $month
     * @return array{name:string,content:string,sha256:string,extension:string}
     */
    public static function workbook(array $month): array
    {
        $book = new Spreadsheet();
        $sheet = $book->getSheet(0);
        $sheet->setTitle(self::SHEET);
        $headers = array_keys($month['columns']);
        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }
        foreach ($month['rows'] as $r => $row) {
            foreach ($headers as $index => $header) {
                $value = $row[$header] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if (is_int($value) || is_float($value)) {
                    $sheet->setCellValue([$index + 1, $r + 2], $value);
                } else {
                    $sheet->setCellValueExplicit([$index + 1, $r + 2], (string) $value, DataType::TYPE_STRING);
                }
            }
        }
        $stream = fopen('php://memory', 'w+b');
        (new Xlsx($book))->save($stream);
        $book->disconnectWorksheets();
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);
        return ['name' => 'mzdy-pohoda-' . $month['period'] . '.xlsx', 'content' => $content, 'sha256' => hash('sha256', $content), 'extension' => 'xlsx'];
    }

    /**
     * @param array<string,mixed> $person
     * @param array<string,mixed> $relation
     */
    private function personalNumber(array $person, array $relation): string
    {
        $number = PohodaXml::text($person, 'OsCislo');
        $order = (int) (PohodaXml::text($relation, 'Poradi') ?: '1');
        if (($this->relationCount[PohodaXml::text($person, 'ID')] ?? 1) > 1 && $order > 1) {
            $number .= '-' . $order;
        }
        return $number;
    }

    private static function bool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', '-1', 'true'], true);
    }

    /** Datum `Y-m-d`; prázdné nebo nulové datum Accessu (před rokem 1901) = null. */
    private static function date(string $value): ?string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1 && (int) $m[1] >= 1901 ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    private static function czechDate(?string $iso): string
    {
        if ($iso === null) {
            return '';
        }
        [$y, $m, $d] = array_map('intval', explode('-', $iso));
        return "{$d}. {$m}. {$y}";
    }

    private static function minor(float $value): int
    {
        return (int) round($value * 100);
    }
}
