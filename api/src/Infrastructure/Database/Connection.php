<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Connection
{
    /**
     * Sdílená PDO spojení TESTOVACÍHO běhu, klíčovaná DSN.
     *
     * Proč to existuje: testy staví DI kontejner per testovací metoda, takže na ~935
     * integračních testů vzniklo 941 nových spojení. Samotný TCP connect na loopback
     * stojí na téhle mašině ~16 ms — a to na JAKÝKOLI port, tedy to není MariaDB, ale
     * síťový stack Windows. Dělalo to ~27 s z ~113 s celé sady. Znovupoužití jednoho
     * socketu tenhle náklad odstraní.
     *
     * Proč NE PDO::ATTR_PERSISTENT: perzistentní spojení si nese session state
     * (rozdělaná transakce, SET time_zone, uživatelské proměnné, named locks) a PDO
     * ho při znovupoužití NEROLLBACKUJE — vzniklo by tiché prosakování stavu mezi
     * testy. Tady se stav uklízí explicitně v resetSharedTestSessions() a rozdělaná
     * transakce se hlásí jako chyba testu (viz Tests\Support\SharedTestConnectionGuard).
     *
     * @var array<string,PDO>
     */
    private static array $sharedPdo = [];

    /**
     * Hloubka „nesdílené" zóny — viz withoutSharedTestConnection(). Počítadlo, ne bool,
     * aby šlo zóny vnořovat.
     */
    private static int $isolationDepth = 0;

    /**
     * Sáhl aktuální test na sdílené spojení? Bez toho by úklid session stavu platily
     * i unit testy, které s DB nepracují (2 zbytečné round-tripy na test).
     */
    private static bool $sharedTouched = false;

    private ?PDO $pdo = null;
    private bool $usesSharedPdo = false;
    private readonly LoggerInterface $logger;
    /** @var array<string,bool> */
    private array $schemaCache = [];
    private readonly bool $sharingAllowed;

    public function __construct(private readonly Config $config, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        // Rozhodnutí padá při KONSTRUKCI, ne až v pdo(): kontejner se staví uvnitř
        // withoutSharedTestConnection(), ale první dotaz přijde až dlouho potom.
        $this->sharingAllowed = self::$isolationDepth === 0;
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            self::$sharedTouched = self::$sharedTouched || $this->usesSharedPdo;

            return $this->pdo;
        }

        $host    = $this->config->get('db.host', '127.0.0.1');
        $port    = (int) $this->config->get('db.port', 3306);
        $name    = (string) $this->config->get('db.name');
        $user    = $this->config->get('db.user');
        $pass    = $this->config->get('db.pass', '');
        $charset = $this->config->get('db.charset', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        $shareable = $this->sharingAllowed && self::sharedTestConnectionsApply($name);

        if ($shareable && isset(self::$sharedPdo[$dsn])) {
            $this->pdo           = self::$sharedPdo[$dsn];
            $this->usesSharedPdo = true;
            self::$sharedTouched = true;

            return $this->pdo;
        }

        $pdo = new LoggingPdo($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ], $this->logger);

        $pdo->exec("SET time_zone = '" . date('P') . "'");

        if ($shareable) {
            self::$sharedPdo[$dsn] = $pdo;
            $this->usesSharedPdo   = true;
            self::$sharedTouched   = true;
        }

        return $this->pdo = $pdo;
    }

    /**
     * Uvolní PDO spojení (nastaví na null → GC zavře MySQL connection). Web ho
     * nepotřebuje (1 connection per request, zavře se na konci), ale testy stavějí
     * container per metodu — bez uvolnění by se connections kumulovaly přes celý
     * běh a narazily na MariaDB max_connections. Při dalším pdo() se vytvoří znovu.
     *
     * U sdíleného testovacího spojení je to no-op: socket drží celý proces a zavírat
     * ho by znamenalo zahodit přesně tu úsporu, kvůli které sdílení existuje. Metoda
     * ale zůstává funkční (a pro nesdílená spojení nezměněná) — limit max_connections
     * na serveru je 60 a nesdílených spojení vzniká jen hrstka.
     */
    public function close(): void
    {
        $this->schemaCache = [];
        if ($this->usesSharedPdo) {
            return;
        }
        $this->pdo = null;
    }

    /**
     * Spustí továrnu tak, aby Connection vzniklé uvnitř NEDOSTALY sdílené testovací
     * spojení, ale vlastní DB session.
     *
     * Nutné všude, kde test ověřuje chování MEZI dvěma sessions — zámek řádku
     * FOR UPDATE, GET_LOCK, viditelnost necommitnutých dat. Se sdíleným spojením by
     * takový test tvrdil, že izolace funguje, aniž by ji reálně změřil.
     *
     * @template T
     * @param callable():T $factory
     * @return T
     */
    public static function withoutSharedTestConnection(callable $factory): mixed
    {
        ++self::$isolationDepth;
        try {
            return $factory();
        } finally {
            --self::$isolationDepth;
        }
    }

    /**
     * Uklidí session stav sdílených testovacích spojení mezi testy a ohlásí, na kterých
     * zůstala rozdělaná transakce.
     *
     * Dřív měl každý test čerstvé PDO, takže nedokončená transakce zmizela implicitním
     * rollbackem při zavření socketu a nikdo se o ní nedozvěděl. Sdílené spojení ji
     * naopak protáhne do dalšího testu — proto se tady rollbackuje a NAHLAS hlásí.
     * Ze stejného důvodu se vrací i session proměnné a named locks do výchozího stavu:
     * `SET FOREIGN_KEY_CHECKS = 0` nebo GET_LOCK dřív padly se spojením, teď ne.
     *
     * @return list<string> DSN spojení, na kterých byla nalezena rozdělaná transakce
     */
    public static function resetSharedTestSessions(): array
    {
        if (!self::$sharedTouched) {
            return [];
        }
        self::$sharedTouched = false;

        $leaked = [];
        foreach (self::$sharedPdo as $dsn => $pdo) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                    $leaked[] = $dsn;
                }
                $pdo->exec('SET SESSION foreign_key_checks = 1, unique_checks = 1, innodb_lock_wait_timeout = DEFAULT');
                $pdo->query('SELECT RELEASE_ALL_LOCKS()');
            } catch (\Throwable) {
                // Spojení je rozbité (server odešel, killnutá session) — zahoď ho ze
                // sdílené mapy, další pdo() postaví nové. Tichý catch je tu na místě:
                // jediná alternativa by byla shodit celý běh na infrastrukturní chybě.
                unset(self::$sharedPdo[$dsn]);
            }
        }

        return $leaked;
    }

    /**
     * Sdílení se zapíná jen pod PHPUnit A ZÁROVEŇ proti databázi se jménem končícím
     * na `_test`. Je to táž pojistka, jakou používá tests/bootstrap.php — žádná nová
     * konfigurace, kterou by šlo omylem zapnout v produkci.
     */
    private static function sharedTestConnectionsApply(string $dbName): bool
    {
        return defined('PHPUNIT_COMPOSER_INSTALL') && str_ends_with($dbName, '_test');
    }

    public function hasColumn(string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            throw new \InvalidArgumentException('Neplatný identifikátor databázového schématu.');
        }
        $key = "column:{$table}.{$column}";
        if (array_key_exists($key, $this->schemaCache)) {
            return $this->schemaCache[$key];
        }

        $pdo = $this->pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $rows = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
            return $this->schemaCache[$key] = array_any(
                $rows,
                static fn (array $row): bool => (string) ($row['name'] ?? '') === $column,
            );
        }

        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        return $this->schemaCache[$key] = $stmt->fetchColumn() !== false;
    }

    public function hasTable(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Neplatný identifikátor databázového schématu.');
        }
        $key = "table:{$table}";
        if (array_key_exists($key, $this->schemaCache)) {
            return $this->schemaCache[$key];
        }

        $pdo = $this->pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
        } else {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
        }
        $stmt->execute([$table]);
        return $this->schemaCache[$key] = $stmt->fetchColumn() !== false;
    }

    public function ping(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
