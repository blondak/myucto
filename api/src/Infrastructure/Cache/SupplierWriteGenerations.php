<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Cache;

/**
 * Generace dat po firmách: čítač v Redisu, který se zvýší, kdykoli request dané
 * firmy něco zapíše. Cache výsledku spočítaného nad daty firmy si generaci vloží
 * do klíče, takže po zápisu přestane být dosažitelná a nemusí se nic mazat.
 *
 * Zápis se odchytává na PDO vrstvě ({@see \MyInvoice\Infrastructure\Database\WriteWatcher}),
 * stejně jako u {@see EntityCache}. Invalidace je záměrně hrubá: zvedne ji KAŽDÝ
 * zápis kromě technických tabulek z {@see IGNORED_TABLES}, ne jen zápis do tabulek,
 * ze kterých výpočet čte. Seznam tabulek, které kontroly čtou, by se musel udržovat
 * souběžně s nimi; přeinvalidovat stojí jen přepočet jedné firmy.
 *
 * Zápis mimo kontext firmy (cron, CLI, migrace, request bez `X-Supplier-Id`) zvedne
 * generaci 0, která je v klíči každé firmy. Zápis requestu firmy A do dat firmy B
 * se neodchytí; ten případ kryje TTL cache.
 *
 * Do Redisu jde nejvýš jeden INCR na firmu za request, až na jeho konci.
 */
final class SupplierWriteGenerations
{
    public const KEY_PREFIX = 'swg:';

    /**
     * Tabulky, do kterých se zapisuje i při čtení nebo mimo účetní data.
     * `crm_action_item_dismissals` uklízí prošlá skrytí úkolů při každém GET
     * `/api/crm/action-items`, takže by každé otevření přehledu zahodilo cache.
     * Fronty integrací a úloh přepisuje cron každou minutu; změny dat, které
     * z nich vzniknou, jdou do datových tabulek a generaci zvednou samy.
     */
    private const IGNORED_TABLES = '/^(?:sessions|rate_limit_counters|login_attempts|user_preferences|user_filters|api_tokens|api_request_log\w*|cron_\w+|instance_storage_usage|telemetry\w*|backup_\w+|smtp_log\w*|migrations|crm_action_item_dismissals|integration_(?:inbox|outbox)|catalog_jobs)$/i';

    private const WRITE_TABLE = '/^\s*(?:INSERT(?:\s+IGNORE)?\s+INTO|REPLACE(?:\s+INTO)?|UPDATE(?:\s+IGNORE)?|DELETE(?:\s+\w+)?\s+FROM|TRUNCATE(?:\s+TABLE)?)\s+`?(\w+)`?/i';

    private static ?RedisFactory $redis = null;
    private static int $requestSupplierId = 0;
    /** @var array<int,true> */
    private static array $dirty = [];
    private static bool $flushRegistered = false;

    public static function bind(?RedisFactory $redis): void
    {
        self::$redis = $redis;
    }

    public static function setRequestSupplier(int $supplierId): void
    {
        self::$requestSupplierId = max(0, $supplierId);
    }

    public static function noteStatement(string $sql): void
    {
        if (self::$redis === null) {
            return;
        }
        $table = self::writtenTable($sql);
        if ($table === null || preg_match(self::IGNORED_TABLES, $table) === 1) {
            return;
        }
        self::$dirty[self::$requestSupplierId] = true;
        if (!self::$flushRegistered) {
            self::$flushRegistered = true;
            register_shutdown_function(static function (): void {
                self::flush();
            });
        }
    }

    /** Tabulka, do které příkaz zapisuje; null u čtení a DDL. */
    public static function writtenTable(string $sql): ?string
    {
        return preg_match(self::WRITE_TABLE, $sql, $m) === 1 ? $m[1] : null;
    }

    /** @return array<int,true> firmy se zápisem v tomhle requestu (0 = mimo kontext firmy) */
    public static function pending(): array
    {
        return self::$dirty;
    }

    public static function flush(): void
    {
        $dirty = self::$dirty;
        self::$dirty = [];
        if ($dirty === [] || self::$redis === null) {
            return;
        }
        self::$redis->run(static function ($c) use ($dirty): void {
            foreach (array_keys($dirty) as $supplierId) {
                $c->incr(self::KEY_PREFIX . $supplierId);
            }
        });
    }

    /** Pro testy: vrátí statický stav do výchozího. */
    public static function reset(): void
    {
        self::$redis = null;
        self::$requestSupplierId = 0;
        self::$dirty = [];
        self::$flushRegistered = false;
    }
}
