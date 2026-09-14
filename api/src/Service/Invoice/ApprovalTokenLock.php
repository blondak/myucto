<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use PDO;
use Throwable;

/**
 * Společný zámek obnovy a zápisů schvalovacích tokenů v jedné databázi.
 *
 * Při volání v cizí transakci zůstane zámek na spojení až do jeho uzavření:
 * MySQL pojmenovaný zámek neuvolňuje při COMMIT a předčasné RELEASE_LOCK by
 * dovolilo obnově kontrolovat dosud necommitnutý výsledek. Kód, který řídí
 * transakci sám, má do callbacku zahrnout i její COMMIT nebo ROLLBACK.
 */
final class ApprovalTokenLock
{
    /** @var \WeakMap<PDO, bool>|null */
    private static ?\WeakMap $active = null;

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function run(PDO $pdo, callable $callback, int $timeoutSeconds = 10): mixed
    {
        if ($timeoutSeconds < 0) {
            throw new \InvalidArgumentException('Neplatná doba čekání na zámek schvalování.');
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            // SQLite serializuje zápisy vlastním databázovým zámkem. Toto není
            // ekvivalent MariaDB GET_LOCK; konkurující transakce skončí SQLITE_BUSY.
            return $callback();
        }
        if ($driver !== 'mysql') {
            throw new \RuntimeException('Zámek schvalování vyžaduje MariaDB/MySQL nebo SQLite.');
        }

        self::$active ??= new \WeakMap();
        if (isset(self::$active[$pdo])) {
            return $callback();
        }

        try {
            $databaseStatement = $pdo->query('SELECT DATABASE()');
            if ($databaseStatement === false) {
                throw new \RuntimeException('Databáze pro zámek schvalování není určena.');
            }
            $database = $databaseStatement->fetchColumn();
            if (!is_string($database) || $database === '') {
                throw new \RuntimeException('Databáze pro zámek schvalování není určena.');
            }
            // Celých 64 znaků SHA-256 splňuje limit GET_LOCK a nevypisuje DB
            // ani token. Normalizace casingu drží společný zámek i na serveru,
            // který stejné jméno schématu přijímá ve více podobách.
            $name = hash('sha256', 'myucto:approval-token-lock:' . strtolower($database));
            $lock = $pdo->prepare('SELECT GET_LOCK(?, ?)');
            $lock->execute([$name, $timeoutSeconds]);
            if ((int) $lock->fetchColumn() !== 1) {
                throw new \RuntimeException('Schvalovací tokeny právě mění jiný požadavek. Zkuste akci znovu.');
            }
        } catch (Throwable $error) {
            if ($error instanceof \RuntimeException) {
                throw $error;
            }
            throw new \RuntimeException('Nepodařilo se získat zámek schvalování.', 0, $error);
        }

        self::$active[$pdo] = true;
        try {
            return $callback();
        } finally {
            unset(self::$active[$pdo]);
            if (!$pdo->inTransaction()) {
                try {
                    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([$name]);
                    if ((int) $release->fetchColumn() !== 1) {
                        throw new \RuntimeException('Nepodařilo se uvolnit zámek schvalování.');
                    }
                } catch (Throwable $error) {
                    if ($error instanceof \RuntimeException) {
                        throw $error;
                    }
                    throw new \RuntimeException('Nepodařilo se uvolnit zámek schvalování.', 0, $error);
                }
            }
        }
    }
}
