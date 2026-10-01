<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use PDO;

/**
 * Stav zpracování pohybů, které založil import výpisu.
 *
 * Import pohyby uloží a potvrdí transakci; převzetí z e-mailového avíza, párování na
 * doklady a zaúčtování běží až potom ({@see StatementImporter}). Spadne-li tahle fáze,
 * pohyby v evidenci zůstanou nezpracované a opakované nahrání téhož souboru je už
 * nezpracuje ({@see StatementImportTransactions}). Jedinou cestou je „Přepárovat výpis"
 * u výpisu, a proto o tom výpis musí vědět.
 *
 * Pohyb dostane `processing_pending_at` už při založení, uvnitř transakce importu. Úspěšné
 * zpracování značku smaže, selhání doplní `processing_error`. Značka bez chyby tak
 * zachytí i proces, který doběhnout nestihl (limit běhu, pád PHP); po {@see GRACE_MINUTES}
 * se bere jako selhání, dřív jde o import, který právě běží.
 */
final class StatementProcessingState
{
    public const GRACE_MINUTES = 10;
    private const ERROR_MAX_LENGTH = 500;

    public static function pendingSince(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** Podmínka „zpracování pohybu selhalo" nad aliasem `bank_transactions`. */
    public static function failedSql(string $alias = 'bt', ?\DateTimeImmutable $now = null): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Invalid transaction alias.');
        }
        $cutoff = ($now ?? new \DateTimeImmutable())
            ->modify('-' . self::GRACE_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s');
        return "($alias.processing_pending_at IS NOT NULL"
            . " AND ($alias.processing_error IS NOT NULL OR $alias.processing_pending_at < '$cutoff'))";
    }

    /** @param list<int> $transactionIds */
    public static function complete(PDO $pdo, array $transactionIds): void
    {
        foreach (array_chunk(array_values(array_unique(array_map('intval', $transactionIds))), 500) as $chunk) {
            $pdo->prepare(
                'UPDATE bank_transactions SET processing_pending_at = NULL, processing_error = NULL
                  WHERE processing_pending_at IS NOT NULL AND id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')'
            )->execute($chunk);
        }
    }

    /** @param list<int> $transactionIds */
    public static function fail(PDO $pdo, array $transactionIds, \Throwable $error): void
    {
        $message = mb_substr(trim($error->getMessage()) !== '' ? trim($error->getMessage()) : $error::class, 0, self::ERROR_MAX_LENGTH, 'UTF-8');
        foreach (array_chunk(array_values(array_unique(array_map('intval', $transactionIds))), 500) as $chunk) {
            $pdo->prepare(
                'UPDATE bank_transactions SET processing_error = ?
                  WHERE processing_pending_at IS NOT NULL AND id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')'
            )->execute([$message, ...$chunk]);
        }
    }

    /** Po úspěšném „Přepárovat výpis" jsou zpracované všechny pohyby výpisu. */
    public static function completeStatement(PDO $pdo, int $statementId): void
    {
        $pdo->exec(
            'UPDATE bank_transactions bt SET bt.processing_pending_at = NULL, bt.processing_error = NULL
              WHERE bt.processing_pending_at IS NOT NULL AND ' . StatementTransactionScope::sql($statementId)
        );
    }

    /**
     * Počet nezpracovaných pohybů výpisu a chyba posledního selhání.
     *
     * @return array{unprocessed_count:int, processing_error:?string}
     */
    public static function forStatement(PDO $pdo, int $statementId): array
    {
        $row = $pdo->query(
            'SELECT COUNT(*) AS unprocessed, MAX(bt.processing_error) AS error
               FROM bank_transactions bt
              WHERE ' . StatementTransactionScope::sql($statementId) . ' AND ' . self::failedSql()
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $count = (int) ($row['unprocessed'] ?? 0);
        return [
            'unprocessed_count' => $count,
            'processing_error' => $count > 0 && isset($row['error']) ? (string) $row['error'] : null,
        ];
    }
}
