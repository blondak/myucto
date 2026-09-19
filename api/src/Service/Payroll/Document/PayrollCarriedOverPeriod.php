<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * Část roku, kterou MyÚčto NESPOČÍTALO — převzaté počáteční stavy kumulací.
 *
 * Zákazník, který přejde z jiného mzdového programu uprostřed roku, má měsíce
 * před `payroll_module_state.start_period` doložené jen počátečním stavem
 * (`payroll_statutory_accumulator_openings`). Roční doklady podle § 38j ZDP je
 * bez něj vystaví jen za vlastní měsíce, a tím vykážou nižší roční úhrn, než
 * jaký skutečně platí.
 *
 * Tahle hodnota je JEDINÉ místo, kde se převzatá část skládá — čte ji potvrzení
 * o zdanitelných příjmech i roční mzdový list. Kdyby ji měl každý builder
 * vlastní, rozešly by se právě ta pravidla, na kterých záleží: co se z openingu
 * bere, co se z něj vzít nedá a kdy se doklad nesmí vystavit vůbec.
 *
 * Převzatá část NENÍ výsledek výpočtu MyÚčta, takže se nikdy nesmí slít
 * s vlastními měsíci do jedné nerozlišené řady. Nese si proto vlastní seznam
 * měsíců a referenci na zdroj, aby je doklad mohl pojmenovat.
 */
final readonly class PayrollCarriedOverPeriod
{
    public const SCHEMA_VERSION = 'payroll-carried-over-period.v1';

    /**
     * Druhy kumulace, ze kterých se převzatá část skládá.
     *
     * `health_insurance` je tu ZÁMĚRNĚ, přestože ho dnes nikdo nezapisuje
     * (PayrollOpeningBalanceService zná jen dva druhy). Čtení jde přes
     * `openingBalance()`, které druh nevaliduje a na chybějící řádek vrací
     * `null`, takže dnes se z tohohle druhu nevezme nic — a jakmile ho průvodce
     * počátečních stavů začne psát, převezme se bez další úpravy kódu. Právě
     * proto se hodnoty ani rozpis měsíců nefiltrují whitelistem polí: prochází
     * každé nezáporné celé číslo, které v openingu je.
     */
    public const KINDS = ['income_tax', 'social_insurance', 'health_insurance'];

    /**
     * @param list<int> $months souvislý výčet převzatých měsíců roku
     * @param list<array<string,int>> $monthRows rozpis po měsících (klíč `month`)
     * @param array<string,array<string,int>> $values druh kumulace => roční úhrny
     * @param array<string,string> $recordHashes druh kumulace => otisk verze openingu
     */
    private function __construct(
        public array $months,
        public string $sourceReference,
        public array $monthRows,
        public array $values,
        public array $recordHashes,
    ) {}

    /**
     * Složí převzatou část z počátečních stavů, nebo vrátí `null`, když žádná není.
     *
     * `$firstProcessedMonth` je první měsíc roku, za který má MyÚčto schválenou
     * revizi. Měsíce pod ním jsou ty, které doklad z vlastního výpočtu pokrýt
     * nemůže.
     *
     * ⚠️ Chybí-li počáteční stav a přitom nějaký takový měsíc existuje, metoda
     * VYHODÍ výjimku a doklad se nevystaví. Je to vědomé rozhodnutí: roční
     * doklad podle § 38j je podklad pro daňové přiznání nebo roční zúčtování
     * a číslo z něj se opíše do formuláře. Neúplný úhrn s poznámkou by se opsal
     * stejně snadno jako úplný, kdežto odmítnutí má konkrétní a proveditelnou
     * nápravu — doplnit počáteční stavy v průvodci. Dnešní chování dokladů je
     * ostatně totéž: bez schválené revize se raději nevystaví, než aby vyšly
     * poloprázdné.
     *
     * @param array<string,array<string,mixed>|null> $openings druh kumulace => aktuální opening
     */
    public static function fromOpenings(
        array $openings,
        int $firstProcessedMonth,
    ): ?self {
        if ($firstProcessedMonth < 1 || $firstProcessedMonth > 12) {
            throw new \InvalidArgumentException(
                'První zpracovaný měsíc roku není platný.',
            );
        }
        $present = [];
        foreach (self::KINDS as $kind) {
            $opening = $openings[$kind] ?? null;
            if ($opening !== null) {
                $present[$kind] = $opening;
            }
        }
        if ($present === []) {
            if ($firstProcessedMonth === 1) {
                // Před lednem není co převzít, takže chybějící počáteční stav
                // není mezera. Tohle je případ naprosté většiny instalací
                // a nesmí se u nich nic změnit.
                return null;
            }

            throw new \DomainException(sprintf(
                'Roční doklad nelze vystavit: MyÚčto počítalo mzdy až od %d. měsíce '
                . 'a za měsíce 1–%d nejsou zadané počáteční stavy z předchozího '
                . 'mzdového programu. Doplňte je v počátečních stavech zaměstnance.',
                $firstProcessedMonth,
                $firstProcessedMonth - 1,
            ));
        }

        $monthRows = [];
        $sourceReference = '';
        $values = [];
        $recordHashes = [];
        foreach ($present as $kind => $opening) {
            $values[$kind] = self::intMap($opening['values'] ?? null, $kind);
            $hash = $opening['record_hash'] ?? null;
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \DomainException(
                    "Počáteční stav {$kind} nemá platný otisk záznamu.",
                );
            }
            $recordHashes[$kind] = $hash;
            // Rozpis měsíců píše průvodce do evidence všech druhů shodně, takže
            // stačí první nalezený; pořadí KINDS drží přednost daňové kumulace.
            if ($monthRows === []) {
                $monthRows = self::monthRows($opening['evidence']['months'] ?? null);
                $reference = $opening['source_reference'] ?? null;
                $sourceReference = is_string($reference) ? trim($reference) : '';
            }
        }
        if ($monthRows === []) {
            // Doložená nula: počáteční stav existuje a říká, že před prvním
            // zpracovaným měsícem žádné cizí zpracování nebylo (nový nástup,
            // nebo celý rok vede MyÚčto). Není co uvádět a není co dopočítávat.
            return null;
        }

        $months = array_column($monthRows, 'month');
        foreach ($months as $month) {
            if ($month >= $firstProcessedMonth) {
                throw new \DomainException(sprintf(
                    'Počáteční stav pokrývá měsíc %d, který MyÚčto zároveň '
                    . 'spočítalo. Doklad by ho započetl dvakrát.',
                    $month,
                ));
            }
        }

        return new self(
            $months,
            $sourceReference,
            $monthRows,
            $values,
            $recordHashes,
        );
    }

    /** Roční úhrn převzatého pole daňové kumulace; neznámé pole je nula. */
    public function taxAmount(string $field): int
    {
        return $this->values['income_tax'][$field] ?? 0;
    }

    /** Popis převzatých měsíců pro doklad, např. „1–7". */
    public function label(): string
    {
        return self::monthRangesLabel($this->months);
    }

    /**
     * Kotva do zdrojového manifestu dokladu.
     *
     * Otisk verze openingu tu být MUSÍ: oprava počátečních stavů je nová verze
     * záznamu a doklad z ní musí vzniknout jako další revize, ne se tiše vrátit
     * ten starý se starými čísly.
     *
     * @return array{months:list<int>,record_hashes:array<string,string>}
     */
    public function manifestAnchor(): array
    {
        $hashes = $this->recordHashes;
        ksort($hashes);

        return ['months' => $this->months, 'record_hashes' => $hashes];
    }

    /** @return array<string,mixed> */
    public function toSnapshot(): array
    {
        $values = $this->values;
        ksort($values);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'months' => $this->months,
            'months_label' => $this->label(),
            'source_reference' => $this->sourceReference,
            'month_rows' => $this->monthRows,
            'values' => $values,
        ];
    }

    /**
     * Zmrazená převzatá část ze snapshotu, připravená pro šablonu.
     *
     * Nepřepočítává se, jen se ověří tvar — snapshot je závazný obsah vydané
     * revize a doklad z něj smí jen číst.
     *
     * @return ?array<string,mixed>
     */
    public static function fromSnapshot(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)
            || array_is_list($value)
            || ($value['schema_version'] ?? null) !== self::SCHEMA_VERSION
        ) {
            throw new \DomainException(
                'Převzatá část roku ve snapshotu nemá podporované schéma.',
            );
        }
        $months = $value['months'] ?? null;
        if (!is_array($months) || !array_is_list($months) || $months === []) {
            throw new \DomainException(
                'Převzatá část roku nemá seznam měsíců.',
            );
        }
        foreach ($months as $month) {
            if (!is_int($month) || $month < 1 || $month > 12) {
                throw new \DomainException(
                    'Měsíc převzaté části roku není platný.',
                );
            }
        }
        $rows = $value['month_rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \DomainException(
                'Převzatá část roku nemá rozpis měsíců.',
            );
        }
        $reference = $value['source_reference'] ?? null;
        $label = $value['months_label'] ?? null;

        return [
            'months' => $months,
            'months_label' => is_string($label) && $label !== ''
                ? $label
                : self::monthRangesLabel($months),
            'source_reference' => is_string($reference) ? $reference : '',
            'month_rows' => $rows,
            'values' => is_array($value['values'] ?? null)
                ? $value['values']
                : [],
        ];
    }

    /**
     * Sloučí vlastní a převzaté měsíce do jedné seřazené řady.
     *
     * Doklad ji potřebuje na řádek „kalendářní měsíce", protože ten se ptá na
     * období, za které jsou uvedené částky — a ty jsou za obojí.
     *
     * @param list<int> $own
     * @param list<int> $carried
     * @return list<int>
     */
    public static function mergeMonths(array $own, array $carried): array
    {
        $merged = array_values(array_unique([...$own, ...$carried]));
        sort($merged, SORT_NUMERIC);

        return $merged;
    }

    /** @param list<int> $months */
    public static function monthRangesLabel(array $months): string
    {
        if ($months === []) {
            return '';
        }
        sort($months, SORT_NUMERIC);
        $ranges = [];
        $start = $months[0];
        $previous = $start;
        foreach (array_slice($months, 1) as $month) {
            if ($month === $previous + 1) {
                $previous = $month;
                continue;
            }
            $ranges[] = $start === $previous ? (string) $start : "{$start}–{$previous}";
            $start = $month;
            $previous = $month;
        }
        $ranges[] = $start === $previous ? (string) $start : "{$start}–{$previous}";

        return implode(', ', $ranges);
    }

    /**
     * @return list<array<string,int>>
     */
    private static function monthRows(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \DomainException(
                    'Rozpis měsíců počátečního stavu není objekt.',
                );
            }
            $month = $row['month'] ?? null;
            if (!is_int($month) || $month < 1 || $month > 12) {
                throw new \DomainException(
                    'Měsíc v rozpisu počátečního stavu není platný.',
                );
            }
            if (isset($rows[$month])) {
                throw new \DomainException(
                    "Měsíc {$month} je v rozpisu počátečního stavu dvakrát.",
                );
            }
            $normalized = ['month' => $month];
            foreach ($row as $field => $amount) {
                if ($field === 'month' || !is_string($field)) {
                    continue;
                }
                // Neznámá pole se nezahazují: až opening pojme zdravotní
                // pojištění, projdou tudy bez zásahu do tohohle souboru.
                if (!is_int($amount) || $amount < 0) {
                    throw new \DomainException(
                        "Částka {$field} v rozpisu počátečního stavu není platná.",
                    );
                }
                $normalized[$field] = $amount;
            }
            ksort($normalized);
            $rows[$month] = $normalized;
        }
        ksort($rows, SORT_NUMERIC);

        return array_values($rows);
    }

    /** @return array<string,int> */
    private static function intMap(mixed $value, string $kind): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException(
                "Počáteční stav {$kind} nemá platné hodnoty.",
            );
        }
        $result = [];
        foreach ($value as $field => $amount) {
            if (!is_string($field) || !is_int($amount) || $amount < 0) {
                throw new \DomainException(
                    "Počáteční stav {$kind} obsahuje neplatnou hodnotu.",
                );
            }
            $result[$field] = $amount;
        }
        ksort($result);

        return $result;
    }
}
