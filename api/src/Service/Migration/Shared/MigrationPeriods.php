<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;

/**
 * Účetní období, do kterého převod z cizího účetního programu zapisuje deník roku.
 *
 * PROČ NE {@see \MyInvoice\Service\Accounting\AccountingPeriodProvisioner}: to je
 * jediné pravidlo pro automatické zakládání období při účtování a importu dokladů,
 * ale převodu nesedí ve třech věcech, které by změnily jeho výsledek:
 *  - zakládá jen firmě, která už podvojné účetnictví vede; převod běží dřív, než se
 *    firmě režim přepne (převádí se do firmy v daňové evidenci);
 *  - hranice odvozuje z existující řady období, kdežto převod je bere ze zdroje (první
 *    období firmy založené během roku začíná dnem vzniku, Money S3) a hledá období
 *    podle roku, ne podle data;
 *  - zapisuje do `activity_log`, převod eviduje období ve své mapě (`*_import_map`),
 *    podle které ho při smazání převodu uklidí.
 * Existující období se stejně jako u provisioneru nikdy nemění; uzavřené se vrací
 * s `locked = true` a rozhodnutí nechá na volajícím.
 */
final class MigrationPeriods
{
    public const CREATED_REASON = 'import';

    public function __construct(private readonly AccountingPeriodRepository $periods) {}

    /**
     * Období roku: existující, nebo nově založené (otevřené, důvod `import`).
     *
     * @param callable(int):void $remember zapíše NOVĚ založené období do mapy převodu
     * @param (callable(int):void)|null $adoptExisting volá se s id EXISTUJÍCÍHO období
     *        (Money S3 ho doplní do mapy, když v ní chybí); null = nic
     * @param bool $warnBoundsDiffer ohlásit, že existující období má jiné hranice
     *        (`period_bounds_differ`), než jaké by převod založil
     * @return array{id:int,starts_on:string,ends_on:string,status:string,locked:bool}
     */
    public function ensure(
        int $supplierId,
        int $year,
        string $startsOn,
        string $endsOn,
        ImportProtocol $protocol,
        string $step,
        callable $remember,
        ?callable $adoptExisting = null,
        bool $warnBoundsDiffer = false,
    ): array {
        $existing = $this->periods->findByYear($supplierId, $year);
        if ($existing === null) {
            $id = $this->periods->create($supplierId, $year, $startsOn, $endsOn, self::CREATED_REASON);
            $remember($id);
            $protocol->count($step, 'periods_created');
            return ['id' => $id, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'status' => 'open', 'locked' => false];
        }
        $id = (int) $existing['id'];
        if ($adoptExisting !== null) {
            $adoptExisting($id);
        }
        if ($warnBoundsDiffer && ((string) $existing['starts_on'] !== $startsOn || (string) $existing['ends_on'] !== $endsOn)) {
            $protocol->warn($step, 'period_bounds_differ', sprintf(
                'Období %d už v MyÚčtu existuje (%s – %s), převod ho použije beze změny hranic.',
                $year, $existing['starts_on'], $existing['ends_on']
            ), ['year' => $year]);
        }
        $status = (string) $existing['status'];
        return [
            'id' => $id,
            'starts_on' => (string) $existing['starts_on'],
            'ends_on' => (string) $existing['ends_on'],
            'status' => $status,
            'locked' => $status !== 'open',
        ];
    }

    /** @return array{0:string,1:string} kalendářní rok: první a poslední den */
    public static function calendarYear(int $year): array
    {
        return [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)];
    }
}
