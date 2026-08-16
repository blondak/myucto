<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

/**
 * Vyloučené a odečítané doby evidenčního listu.
 *
 * Tohle je nejcitlivější místo celého ELDP: vyloučené doby zvedají osobní
 * vyměřovací základ tím, že se odečtou ze jmenovatele, takže chyba tady
 * změní důchod. Proto se tu nic nedopočítává „rozumným odhadem“ — modul
 * odvodí jen to, co má bezpečně doložené v zmrazeném snapshotu, a všechno
 * ostatní vrátí jako blokátor.
 *
 * ## Co se odvozuje (§ 16 odst. 4 písm. a) zákona č. 155/1995 Sb.)
 *
 * | druh absence          | atribut ELDP                        |
 * |-----------------------|-------------------------------------|
 * | `dpn`, `quarantine`   | 10358 dočasná pracovní neschopnost  |
 * | `ppm`                 | 10359 peněžitá pomoc v mateřství    |
 * | `ocr`,`long_term_care`| 10360 ošetřovné / dlouhodobé ošetř. |
 * | `paternity`           | 10362 otcovská                      |
 *
 * Součet 10357 = 10358 + 10359 + 10360 + 10362 + 10536 podle *Pravidel podání
 * JMHZ a souvisejících procesů* verze 1.4.4, kapitola 4 (ELDP).
 *
 * ## Co vyloučenou dobu netvoří a nechává řádek beze změny
 *
 * `vacation` (dovolená se proplácí a pojištění běží dál) a `employer_obstacle`
 * (překážka na straně zaměstnavatele s náhradou mzdy). Ani jedno nesnižuje
 * dobu pojištění ani netvoří vyloučenou dobu.
 *
 * ## Co je fail-closed a proč
 *
 * - `unpaid_leave` — neplacené volno se podle situace promítá do 10473
 *   (omluvená nepřítomnost bez náhrady) nebo do 10468 (odečítané dny po
 *   dosažení důchodového věku). Rozhodnutí mezi nimi vyžaduje údaj, který
 *   modul nemá.
 * - `parental` — rodičovská dovolená je náhradní doba pojištění řešená kódem
 *   ELDP a přerušením pojištění, ne vyloučenou dobou; logiku změny kódu
 *   uvnitř roku modul zatím nemá.
 * - `employee_obstacle` — z druhu absence nejde poznat, jestli za ni náleží
 *   náhrada příjmu; placená překážka vyloučenou dobu netvoří, neplacená ano.
 * - `other` — nerozlišený druh.
 *
 * ## Co se z principu nevyplňuje
 *
 * - **10536** (§ 16 odst. 4 písm. j)) a **10366** (§ 18 odst. 7 zákona
 *   č. 187/2006 Sb.) — modul pro ně nemá žádný vstup, drží se na nule a je to
 *   vidět v součtovém pravidle.
 * - **10474 / 10475** — rozpad nemoci na dny s náhradou příjmu od
 *   zaměstnavatele a na dny s vyplacenou dávkou. Okno prvních čtrnácti dnů
 *   sice `payroll_sickness_events` zná, ale ve zmrazeném snapshotu mzdové
 *   revize není; a jestli dávku ČSSZ skutečně přiznala, zaměstnavatel neví
 *   vůbec. Oba atributy jsou nepovinné, takže se neuvádějí.
 * - **Odečítané doby (10375 a 10462–10469)** se týkají výhradně dob po
 *   dosažení důchodového věku. Modul důchodový věk nezná — nepočítá ho ani
 *   ho neeviduje — takže odečítané doby nedopočítává. Nula na řádku je proto
 *   podmíněná výslovným lidským potvrzením, ne odvozením.
 */
final class EldpExcludedPeriodDeriver
{
    /** Druh absence → atribut vyloučené doby podle § 16 odst. 4 písm. a). */
    private const EXCLUDED_ATTRIBUTES = [
        'dpn' => 'docasNeschopnost',
        'quarantine' => 'docasNeschopnost',
        'ppm' => 'penezitaPomocMaterstvi',
        'ocr' => 'osetrovaniClenaRodiny',
        'long_term_care' => 'osetrovaniClenaRodiny',
        'paternity' => 'otcovska',
    ];

    /** Druhy absence, které dobu pojištění ani vyloučenou dobu nemění. */
    private const NEUTRAL_TYPES = ['vacation', 'employer_obstacle'];

    /** Druhy absence, u kterých modul nemá doložený způsob výpočtu. */
    private const UNSUPPORTED_TYPES = [
        'unpaid_leave' => 'neplacené volno nelze bez dalšího údaje rozdělit mezi omluvenou nepřítomnost a odečítané dny',
        'parental' => 'rodičovská dovolená se řeší kódem ELDP a přerušením pojištění, ne vyloučenou dobou',
        'employee_obstacle' => 'u překážky na straně zaměstnance není doloženo, zda za ni náleží náhrada příjmu',
        'other' => 'nerozlišený druh absence',
    ];

    public const COMPONENTS = [
        'docasNeschopnost',
        'penezitaPomocMaterstvi',
        'osetrovaniClenaRodiny',
        'otcovska',
        'vyloucenePar16',
    ];

    /**
     * @param list<array<string,mixed>> $absences absence ze zmrazeného snapshotu
     * @return array{
     *   components:array<string,int>,
     *   total:int,
     *   provenance:list<array{
     *     absence_id:int,absence_type:string,attribute:string,
     *     absence_from:string,absence_to:string,
     *     counted_from:string,counted_to:string,days:int
     *   }>,
     *   blockers:list<array{code:string,message:string,detail:array<string,mixed>}>
     * }
     */
    public function derive(
        array $absences,
        string $intervalFrom,
        string $intervalTo,
        string $periodLabel,
    ): array {
        $components = array_fill_keys(self::COMPONENTS, 0);
        $provenance = [];
        $blockers = [];
        $claimedDays = [];

        foreach ($absences as $absence) {
            $absenceId = $absence['id'] ?? null;
            $type = $absence['absence_type'] ?? null;
            if (!is_int($absenceId) || $absenceId <= 0 || !is_string($type)) {
                $blockers[] = [
                    'code' => 'eldp_absence_source_invalid',
                    'message' => "Absence v období {$periodLabel} nemá použitelnou identifikaci ani druh.",
                    'detail' => ['period' => $periodLabel],
                ];
                continue;
            }
            $from = self::date($absence['date_from'] ?? null);
            $to = self::date($absence['date_to'] ?? null);
            if ($from === null || $to === null || $from > $to) {
                $blockers[] = [
                    'code' => 'eldp_absence_interval_invalid',
                    'message' => "Absence #{$absenceId} v období {$periodLabel} nemá platný interval.",
                    'detail' => ['absence_id' => $absenceId, 'period' => $periodLabel],
                ];
                continue;
            }
            $countedFrom = max($from, $intervalFrom);
            $countedTo = min($to, $intervalTo);
            if ($countedFrom > $countedTo) {
                continue;
            }
            if (in_array($type, self::NEUTRAL_TYPES, true)) {
                continue;
            }
            if (array_key_exists($type, self::UNSUPPORTED_TYPES)) {
                $blockers[] = [
                    'code' => 'eldp_absence_kind_unsupported',
                    'message' => "Absence #{$absenceId} ({$type}) v období {$periodLabel} nemá doložený "
                        . 'způsob zápisu do evidenčního listu — '
                        . self::UNSUPPORTED_TYPES[$type] . '.',
                    'detail' => [
                        'absence_id' => $absenceId,
                        'absence_type' => $type,
                        'period' => $periodLabel,
                    ],
                ];
                continue;
            }
            $attribute = self::EXCLUDED_ATTRIBUTES[$type] ?? null;
            if ($attribute === null) {
                $blockers[] = [
                    'code' => 'eldp_absence_kind_unknown',
                    'message' => "Absence #{$absenceId} má neznámý druh {$type} a evidenční list ji neumí zapsat.",
                    'detail' => [
                        'absence_id' => $absenceId,
                        'absence_type' => $type,
                        'period' => $periodLabel,
                    ],
                ];
                continue;
            }
            $days = self::inclusiveDays($countedFrom, $countedTo);
            $overlap = self::claim($claimedDays, $countedFrom, $days);
            if ($overlap !== null) {
                $blockers[] = [
                    'code' => 'eldp_absence_overlap_unsupported',
                    'message' => "Absence #{$absenceId} se v období {$periodLabel} překrývá s jinou "
                        . "vyloučenou dobou ({$overlap}); souběh by se ve vyloučených dnech započítal dvakrát.",
                    'detail' => [
                        'absence_id' => $absenceId,
                        'overlapping_day' => $overlap,
                        'period' => $periodLabel,
                    ],
                ];
                continue;
            }
            $components[$attribute] += $days;
            $provenance[] = [
                'absence_id' => $absenceId,
                'absence_type' => $type,
                'attribute' => $attribute,
                'absence_from' => $from,
                'absence_to' => $to,
                'counted_from' => $countedFrom,
                'counted_to' => $countedTo,
                'days' => $days,
            ];
        }

        usort(
            $provenance,
            static fn (array $left, array $right): int =>
                [$left['counted_from'], $left['absence_id']]
                <=> [$right['counted_from'], $right['absence_id']],
        );

        return [
            'components' => $components,
            'total' => array_sum($components),
            'provenance' => $provenance,
            'blockers' => $blockers,
        ];
    }

    public static function inclusiveDays(string $from, string $to): int
    {
        return (new \DateTimeImmutable($from))
            ->diff(new \DateTimeImmutable($to))
            ->days + 1;
    }

    /**
     * @param array<string,true> $claimed
     * @return string|null první den, který už patřil jiné vyloučené době
     */
    private static function claim(array &$claimed, string $from, int $days): ?string
    {
        $cursor = new \DateTimeImmutable($from);
        $taken = [];
        for ($index = 0; $index < $days; ++$index) {
            $day = $cursor->format('Y-m-d');
            if (isset($claimed[$day])) {
                return $day;
            }
            $taken[] = $day;
            $cursor = $cursor->modify('+1 day');
        }
        foreach ($taken as $day) {
            $claimed[$day] = true;
        }

        return null;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }
}
