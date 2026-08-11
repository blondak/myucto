<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

use PDO;
use Throwable;

/**
 * Režim plánování cronu — čtení a přepínání (migrace 1184).
 *
 *   INDIVIDUAL — 20 samostatných položek v crontabu / Task Scheduleru.
 *                Průhledné, laditelné po jedné úloze, žádná sdílená komponenta.
 *                Drží ho každá instalace, která vznikla před migrací 1320.
 *
 *   DISPATCHER — jediná položka `cron-dispatch` každou minutu, která si sama
 *                spočítá, co je na řadě. Míň procesů, ale zavádí jeden bod,
 *                jehož výpadek zastaví všechno. Od migrace 1320 je to výchozí
 *                volba NOVÝCH instalací; existující se nepřepínají.
 *
 * Oba režimy zůstávají plnohodnotné. Přepnutí NENÍ jednosměrné a nemění
 * chování jednotlivých úloh — jen to, kdo je spouští.
 *
 * ⚠️ Změna režimu se projeví až přegenerováním plánu (restart kontejneru,
 * nebo ruční úprava crontabu / Task Scheduleru u nativních instalací).
 * Samotný zápis do DB nic nepřeplánuje — UI na to musí upozornit.
 */
final class CronScheduleMode
{
    public const INDIVIDUAL = 'individual';
    public const DISPATCHER = 'dispatcher';

    /** Skript dispatcheru — v katalogu označený `dispatcher_only`. */
    public const DISPATCHER_SCRIPT = 'cron-dispatch';

    /**
     * Fail-open do INDIVIDUAL: chybí-li tabulka (před migrací) nebo je DB
     * nedostupná, platí původní chování. Tichý přechod na dispatcher u instalace,
     * která ho nemá naplánovaný, by znamenal, že nepoběží vůbec nic. Platí i po
     * migraci 1320, která mění jen výchozí HODNOTU v DB — nedostupná DB pořád
     * musí spadnout do režimu, jehož výpadek zastaví nejmíň.
     */
    public static function current(?PDO $pdo): string
    {
        if ($pdo === null) {
            return self::INDIVIDUAL;
        }
        try {
            $stmt = $pdo->query('SELECT schedule_mode FROM cron_settings WHERE id = 1');
            $value = $stmt === false ? false : $stmt->fetchColumn();
            return self::normalize(is_string($value) ? $value : null);
        } catch (Throwable) {
            return self::INDIVIDUAL;
        }
    }

    public static function set(PDO $pdo, string $mode, ?int $userId): void
    {
        $mode = self::normalize($mode);
        $stmt = $pdo->prepare(
            'INSERT INTO cron_settings (id, schedule_mode, updated_at, updated_by)
             VALUES (1, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE schedule_mode = VALUES(schedule_mode),
                                     updated_at    = NOW(),
                                     updated_by    = VALUES(updated_by)'
        );
        $stmt->execute([$mode, $userId]);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::INDIVIDUAL, self::DISPATCHER];
    }

    public static function isValid(string $mode): bool
    {
        return in_array($mode, self::all(), true);
    }

    private static function normalize(?string $mode): string
    {
        $mode = strtolower(trim((string) $mode));
        return self::isValid($mode) ? $mode : self::INDIVIDUAL;
    }
}
