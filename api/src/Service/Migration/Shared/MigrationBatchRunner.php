<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Fronta převodů více firem najednou (dávka účetní kanceláře): položky běží po jedné,
 * pád jedné firmy nezastaví ostatní a výsledek každé se zapíše hned, jak doběhne.
 *
 * Běžec nic nepřevádí sám — převod jedné položky dodá volající. Totéž pravidlo tak
 * platí pro dávku z průvodce (job na pozadí) i z příkazové řádky.
 */
final class MigrationBatchRunner
{
    public const COMPLETED = 'completed';
    public const COMPLETED_WITH_WARNINGS = 'completed_with_warnings';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';
    public const CANCELLED = 'cancelled';

    /**
     * @template T
     * @param list<T> $items položky v pořadí, v jakém mají běžet
     * @param callable(T,int):array<string,mixed> $runItem převod položky; vrací aspoň `status`
     * @param callable(\Throwable):string $describeError text chyby pro protokol (výjimka zdroje
     *        svým textem, jiná obecnou hláškou a do logu serveru)
     * @param (callable():bool)|null $shouldCancel požádal uživatel o zrušení? (před každou položkou)
     * @param (callable(T,int):void)|null $onStart položka začíná
     * @param (callable(T,int,array<string,mixed>):void)|null $onDone položka skončila (i chybou nebo zrušením)
     * @return list<array<string,mixed>> výsledky položek ve stejném pořadí
     */
    public function run(
        array $items,
        callable $runItem,
        callable $describeError,
        ?callable $shouldCancel = null,
        ?callable $onStart = null,
        ?callable $onDone = null,
    ): array {
        $results = [];
        $cancelled = false;
        foreach (array_values($items) as $index => $item) {
            if (!$cancelled && $shouldCancel !== null && $shouldCancel()) {
                $cancelled = true;
            }
            if ($cancelled) {
                $result = ['status' => self::CANCELLED, 'error' => null];
            } else {
                if ($onStart !== null) {
                    $onStart($item, $index);
                }
                try {
                    $result = $runItem($item, $index);
                    $result['status'] = (string) ($result['status'] ?? self::FAILED);
                } catch (\Throwable $e) {
                    $result = ['status' => self::FAILED, 'error' => $describeError($e)];
                }
                // Převod zrušený uživatelem uprostřed položky zruší i zbytek dávky.
                $cancelled = $result['status'] === self::CANCELLED;
            }
            if ($onDone !== null) {
                $onDone($item, $index, $result);
            }
            $results[] = $result;
        }
        return $results;
    }

    /**
     * Stav celé dávky ze stavů položek. Přeskočená položka (firma už převedená, dávka ji
     * nemá převádět znovu) stav neovlivní. Selže-li jen část firem, dávka doběhla
     * s upozorněním — ostatní firmy jsou převedené.
     *
     * @param list<string> $statuses
     */
    public static function batchStatus(array $statuses): string
    {
        $relevant = array_values(array_filter($statuses, static fn (string $s): bool => $s !== self::SKIPPED));
        if ($relevant === []) {
            return self::COMPLETED;
        }
        if (in_array(self::CANCELLED, $relevant, true)) {
            return self::CANCELLED;
        }
        $failed = count(array_filter($relevant, static fn (string $s): bool => $s === self::FAILED));
        if ($failed === count($relevant)) {
            return self::FAILED;
        }
        if ($failed > 0 || in_array(self::COMPLETED_WITH_WARNINGS, $relevant, true)) {
            return self::COMPLETED_WITH_WARNINGS;
        }
        return self::COMPLETED;
    }
}
