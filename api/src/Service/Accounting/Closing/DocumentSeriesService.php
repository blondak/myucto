<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentSeriesRepository;

/**
 * Číselné řady deníku (Epic F4, R13 / F1 B-b — §11 ZoÚ označení dokladu).
 *
 * Výdej čísla MUSÍ běžet uvnitř probíhající transakce volajícího
 * (ClosingService/akce ji drží): lazy INSERT řádku řady → SELECT ... FOR UPDATE
 * → UPDATE čítače → formát `{prefix}-{fiscal_year}-{NNNN}`. FOR UPDATE
 * eliminuje race dvou souběžných výdejů; mezery po smazaných zápisech se
 * nedorovnávají (R12/R13 — dokumentováno).
 */
final class DocumentSeriesService
{
    /** Výchozí prefixy řad (R13); per firma editovatelné přes updatePrefix. */
    public const DEFAULT_PREFIXES = [
        'closing'  => 'UZ',
        'opening'  => 'OT',
        'fx'       => 'KR',
        'transfer' => 'PP',
        'manual'   => 'ID',
        'cash_in'  => 'PPD',
        'cash_out' => 'VPD',
        'stock_in'       => 'PRI',
        'stock_out'      => 'VYD',
        'stock_transfer' => 'PRE',
        'offset'         => 'ZAP',
    ];

    private const PREFIX_PATTERN = '/^[A-Z0-9]{1,10}$/';

    public function __construct(
        private readonly Connection $db,
        private readonly DocumentSeriesRepository $series,
    ) {}

    /**
     * Vydá další číslo řady, např. `UZ-2026-0001`. Vyžaduje transakci
     * volajícího — atomicita výdeje s INSERTem zápisu (R13).
     */
    public function next(int $supplierId, string $seriesCode, int $fiscalYear): string
    {
        $defaultPrefix = self::DEFAULT_PREFIXES[$seriesCode]
            ?? throw new ClosingException('unknown_series', 'Neznámá číselná řada "' . $seriesCode . '".');
        if (!$this->db->pdo()->inTransaction()) {
            throw new ClosingException(
                'series_transaction_required',
                'Výdej čísla řady ' . $seriesCode . ' vyžaduje probíhající transakci (FOR UPDATE zámek).',
                500,
            );
        }

        $this->series->ensure($supplierId, $seriesCode, $fiscalYear, $defaultPrefix);
        $row = $this->series->lockRow($supplierId, $seriesCode, $fiscalYear);
        if ($row === null) {
            throw new ClosingException(
                'operation_failed',
                'Řadu ' . $seriesCode . '/' . $fiscalYear . ' se nepodařilo zamknout pro výdej čísla.',
                500,
            );
        }
        $this->series->bumpNextNumber($row['id']);

        return self::format($row['prefix'], $fiscalYear, $row['next_number']);
    }

    /**
     * Formát čísla dokladu řady — čistá funkce (unit-testovatelná bez DB).
     */
    public static function format(string $prefix, int $fiscalYear, int $number): string
    {
        return sprintf('%s-%d-%04d', $prefix, $fiscalYear, $number);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId): array
    {
        return $this->series->list($supplierId);
    }

    /**
     * Nastaví prefix řady (validace `^[A-Z0-9]{1,10}$`); řádek lazy založí,
     * takže edit funguje i před prvním výdejem čísla.
     */
    public function updatePrefix(int $supplierId, string $seriesCode, int $fiscalYear, string $prefix): bool
    {
        if (!isset(self::DEFAULT_PREFIXES[$seriesCode])) {
            throw new ClosingException('unknown_series', 'Neznámá číselná řada "' . $seriesCode . '".');
        }
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw new ClosingException(
                'invalid_prefix',
                'Prefix řady musí být 1–10 znaků A–Z/0–9 (zadáno "' . $prefix . '").',
            );
        }
        return $this->series->upsertPrefix($supplierId, $seriesCode, $fiscalYear, $prefix);
    }
}
