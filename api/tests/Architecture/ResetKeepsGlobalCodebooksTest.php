<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Infrastructure\Database\SqlStatementSplitter;
use MyInvoice\Service\System\GlobalSeedRestorer;
use MyInvoice\Service\System\GlobalSeedTables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `reset.php` maže UŽIVATELSKÁ data. Globální číselník ani provozní údaj instance
 * uživatelská data nejsou — a hlavně je po smazání nikdo nevrátí: seedují je migrace,
 * které jsou evidované jako proběhlé, takže je `migrate.php` znovu nespustí.
 *
 * Nález z hostované instance: po `reset.php` zmizel `oss_member_state_rates` a import
 * i vystavení začaly odmítat KAŽDÝ doklad se sazbou vyšší než 0 %. Druhý nález:
 * keep-list zaostal za migracemi a reset smazal číselník svátků (1781) i katalog
 * klíčových slov nákladů (1740), lhůty mezd pak běžely z pojistky v kódu.
 *
 * Proto se keep-list nepíše ručně podle paměti: guard projde KAŽDÝ INSERT všech
 * migrací a tabulku, která může nést globální řádky, nutí zařadit do
 * {@see GlobalSeedTables} (ponechat, smazat jen per-tenant část, nebo jmenovitě
 * povolit smazání s důvodem).
 */
final class ResetKeepsGlobalCodebooksTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $snapshot = null;

    /**
     * JÁDRO GUARDU. Tabulka, kterou některá migrace plní, a která nemá `supplier_id`
     * (nebo ho má nullable, tedy nese globální řádky), musí být zařazená.
     */
    public function testEveryGlobalTableSeededByMigrationsIsClassified(): void
    {
        $tables = self::snapshot()['tables'];
        $dropped = self::droppedTables();
        $classified = GlobalSeedTables::RESET_KEEP + GlobalSeedTables::RESET_PARTIAL + GlobalSeedTables::RESET_WIPES;

        $missing = [];
        foreach (self::migrationFiles() as $file) {
            foreach (SqlStatementSplitter::split((string) file_get_contents($file)) as $statement) {
                $target = GlobalSeedRestorer::statementTarget($statement);
                if ($target === null || !in_array($target['kind'], ['insert', 'replace'], true)) {
                    continue;
                }
                $table = $target['table'];
                if (!isset($tables[$table])) {
                    // Dočasná tabulka migrace je v pořádku; cokoli jiného znamená
                    // zastaralý otisk a guard by tabulku tiše přeskočil.
                    if (!isset($dropped[$table])) {
                        $missing[$table] = basename($file) . ': tabulka chybí v db/schema.snapshot.json — přegeneruj otisk';
                    }
                    continue;
                }
                $supplierId = $tables[$table]['columns']['supplier_id'] ?? null;
                $mayHoldGlobalRows = $supplierId === null || !str_contains((string) $supplierId, 'NOT NULL');
                if ($mayHoldGlobalRows && !isset($classified[$table])) {
                    $missing[$table] = basename($file);
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            "Migrace plní tabulky, které můžou nést globální řádky, ale GlobalSeedTables je nezná.\n"
            . "reset.php by je smazal a migrate.php je nevrátí. Zařaď je do RESET_KEEP (celá globální),\n"
            . "RESET_PARTIAL (smíšená) nebo RESET_WIPES (s důvodem, proč je smazat správně).",
        );
    }

    /** @return list<array{0:string, 1:string}> [tabulka, proč ji reset nesmí smazat] */
    public static function globalTables(): array
    {
        return [
            ['oss_member_state_rates', 'legislativní sazby DPH členských států (seed 1152/1292/1294)'],
            ['public_holidays', 'svátky posouvají lhůty podání i odvodů (seed 1781)'],
            ['expense_keyword_catalog', 'globální katalog klíčových slov nákladů (seed 1740/1742/1743)'],
            ['payroll_data_migration_markers', 'evidence jednorázových převodů v migracích (1194/1204)'],
            ['license', 'licence instance — znovuzaložení ji degraduje na trial'],
            ['backup_schedule_contract', 'parametry sjednané s poskytovatelem hostingu'],
            ['instance_storage_usage', 'podklad pro měření úložiště u poskytovatele'],
        ];
    }

    #[DataProvider('globalTables')]
    public function testResetKeepsGlobalTable(string $table, string $why): void
    {
        self::assertContains(
            $table,
            GlobalSeedTables::resetKeep(false),
            "reset.php musí ponechat `{$table}` — {$why}. Po smazání ho migrate.php nevrátí.",
        );
    }

    /**
     * Číselník příjemců podání má `supplier_id`, ale globální řádky jsou legislativní
     * údaj. Reset proto smí smazat jen per-tenant override.
     */
    public function testResetDeletesOnlyTenantSubmissionRecipients(): void
    {
        self::assertSame('supplier_id IS NOT NULL', GlobalSeedTables::RESET_PARTIAL['submission_recipients'] ?? null);
    }

    /** Reset čte seznamy z SSOT — vlastní kopie ve skriptu by znovu zaostala. */
    public function testResetScriptUsesTheRegistry(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/reset.php');

        self::assertStringContainsString('GlobalSeedTables::resetKeep($keepCache)', $source);
        self::assertStringContainsString('GlobalSeedTables::RESET_PARTIAL', $source);
        self::assertDoesNotMatchRegularExpression(
            "/\\\$keep\s*=\s*\[/",
            $source,
            'reset.php nesmí mít vlastní keep-list, patří do GlobalSeedTables::RESET_KEEP.',
        );
    }

    /** Zařazení nesmí být zastaralé ani sporné. */
    public function testRegistryIsConsistentWithSchema(): void
    {
        $tables = self::snapshot()['tables'];
        $all = array_merge(
            array_keys(GlobalSeedTables::RESET_KEEP),
            GlobalSeedTables::RESET_KEEP_CACHE,
            array_keys(GlobalSeedTables::RESET_PARTIAL),
            array_keys(GlobalSeedTables::RESET_WIPES),
            array_keys(GlobalSeedTables::CODEBOOKS),
            array_keys(GlobalSeedTables::RESTORABLE),
        );
        $unknown = array_values(array_filter(array_unique($all), static fn (string $t): bool => !isset($tables[$t])));
        self::assertSame([], $unknown, 'GlobalSeedTables jmenuje tabulky, které ve schématu nejsou.');

        $kept = GlobalSeedTables::RESET_KEEP + GlobalSeedTables::RESET_PARTIAL;
        self::assertSame(
            [],
            array_keys(array_intersect_key($kept, GlobalSeedTables::RESET_WIPES)),
            'Tabulka nemůže být zároveň ponechaná i povolená ke smazání.',
        );
        self::assertSame(
            [],
            array_keys(array_intersect_key(GlobalSeedTables::RESET_KEEP, GlobalSeedTables::RESET_PARTIAL)),
            'Tabulka je buď ponechaná celá, nebo částečně — ne obojí.',
        );
        self::assertSame(
            [],
            array_keys(array_diff_key(GlobalSeedTables::CODEBOOKS + GlobalSeedTables::RESTORABLE, $kept)),
            'Číselník, který Diagnostika hlídá nebo dotah obnovuje, musí reset ponechat.',
        );
    }

    /**
     * Dotah přehrává statementy migrací naslepo, takže musí platit: zná VŠECHNY
     * migrace, které tabulku mění (jinak by vrátil starý stav), žádná z tabulky
     * nemaže (jinak by vzkřísil řádek, který migrace vědomě odstranila), INSERTy
     * jsou idempotentní a UPDATE u smíšené tabulky sahá jen na globální řádky.
     */
    public function testRestorableSeedsAreSafeToReplay(): void
    {
        $dir = self::migrationsDir();
        foreach (GlobalSeedTables::RESTORABLE as $table => $spec) {
            $statements = GlobalSeedRestorer::tableStatements($dir, $table);
            self::assertNotSame([], $statements, "{$table}: žádná migrace tabulku neplní.");

            $migrations = array_values(array_unique(array_column($statements, 'migration')));
            self::assertSame(
                $migrations,
                $spec['migrations'],
                "{$table}: GlobalSeedTables::RESTORABLE musí jmenovat přesně migrace, které tabulku mění, v pořadí.",
            );

            foreach ($statements as $statement) {
                $where = "{$table} v {$statement['migration']}";
                self::assertNotContains($statement['kind'], ['delete', 'replace'], "{$where}: migrace z tabulky maže, dotah by smazaný řádek vzkřísil.");
                if ($statement['kind'] === 'insert') {
                    self::assertTrue(GlobalSeedRestorer::isIdempotentInsert($statement['sql']), "{$where}: INSERT není idempotentní.");
                }
                if ($statement['kind'] === 'update' && $spec['global'] !== null) {
                    self::assertStringContainsString(
                        self::normalize($spec['global']),
                        self::normalize($statement['sql']),
                        "{$where}: UPDATE smíšené tabulky musí být omezený na globální řádky ({$spec['global']}).",
                    );
                }
            }
        }
    }

    /**
     * Hlášky uživateli radí spustit `migrate.php`. Ta rada platí jen tehdy, když
     * migrate.php prázdný číselník opravdu dotáhne.
     */
    public function testMigrateSelfHealsGlobalSeeds(): void
    {
        $migrate = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/migrate.php');

        self::assertStringContainsString('backfill-oss-rates.php', $migrate);
        self::assertStringContainsString(
            'oss_member_state_rates WHERE is_custom = 0',
            $migrate,
            'Podmínka se musí ptát na SEEDOVANÉ řádky — vlastní sazba uživatele nesmí díru zamaskovat.',
        );
        self::assertStringContainsString('restore-global-seeds.php', $migrate);
        self::assertFileExists(dirname(__DIR__, 2) . '/bin/restore-global-seeds.php');

        $body = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/backfill-oss-rates.php');
        self::assertStringContainsString(
            '1319_oss_member_state_rates_self_heal.sql',
            $body,
            'Dotah musí použít sebeopravnou migraci, ne vlastní kopii 85 řádků sazeb.',
        );
    }

    /** Skript dotahu má dvojče pro každou platformu (AGENTS.md). */
    public function testRestoreScriptHasBothPlatformWrappers(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['cmd/restore-global-seeds.ps1', 'cmd/restore-global-seeds.sh'] as $wrapper) {
            self::assertFileExists($root . '/' . $wrapper);
            self::assertStringContainsString('restore-global-seeds.php', (string) file_get_contents($root . '/' . $wrapper));
        }
    }

    private static function normalize(string $sql): string
    {
        return strtolower((string) preg_replace('/\s+/', ' ', $sql));
    }

    /** @return array<string, true> */
    private static function droppedTables(): array
    {
        $out = [];
        foreach (self::migrationFiles() as $file) {
            if (preg_match_all('/DROP\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?/i', (string) file_get_contents($file), $m)) {
                foreach ($m[1] as $table) {
                    $out[strtolower($table)] = true;
                }
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function migrationFiles(): array
    {
        $files = glob(self::migrationsDir() . '/*.sql') ?: [];
        sort($files);
        return $files;
    }

    private static function migrationsDir(): string
    {
        return dirname(__DIR__, 3) . '/db/migrations';
    }

    /** @return array<string, mixed> */
    private static function snapshot(): array
    {
        if (self::$snapshot === null) {
            $json = file_get_contents(dirname(__DIR__, 3) . '/db/schema.snapshot.json');
            self::assertIsString($json);
            self::$snapshot = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        }
        return self::$snapshot;
    }
}
