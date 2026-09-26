<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\PhpSourceRegions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Stereo NX translates source records; shared migration services own business-table writes. */
#[Group('architecture')]
final class StereoNxSharedWriterBoundaryTest extends TestCase
{
    private const IMPORT_MAP_FILE = 'StereoNxImportMap.php';
    private const IMPORT_MAP_SYMBOL = 'put';

    public function testSourceAdapterDoesNotWriteBusinessTablesWithSql(): void
    {
        $offenders = [];
        foreach (glob(self::sourceDir() . '/*.php') ?: [] as $path) {
            $source = (string) file_get_contents($path);
            foreach (self::sqlWrites($source) as $write) {
                if (self::allowed(basename($path), $write)) {
                    continue;
                }
                $offenders[] = basename($path) . '::' . $write['symbol'] . ':' . $write['line']
                    . ' ' . $write['operation'] . ' ' . $write['table'];
            }
        }

        self::assertSame([], $offenders, "Stereo NX must write business tables through shared services:\n"
            . implode("\n", $offenders));
    }

    public function testImportMapExceptionNamesAnExistingWritingMethod(): void
    {
        $source = (string) file_get_contents(self::sourceDir() . '/' . self::IMPORT_MAP_FILE);
        self::assertSame([], PhpSourceRegions::missingSymbols($source, [self::IMPORT_MAP_SYMBOL]));
        self::assertNotEmpty(array_filter(
            self::sqlWrites($source),
            static fn (array $write): bool => self::allowed(self::IMPORT_MAP_FILE, $write),
        ), 'The symbol-scoped import-map exception must still be needed.');
    }

    #[DataProvider('sqlSamples')]
    public function testGuardRecognizesSqlWithoutReadingComments(string $file, string $source, bool $unsafe): void
    {
        $offenders = array_filter(
            self::sqlWrites($source),
            static fn (array $write): bool => !self::allowed($file, $write),
        );
        self::assertSame($unsafe, $offenders !== []);
    }

    /** @return iterable<string,array{string,string,bool}> */
    public static function sqlSamples(): iterable
    {
        yield 'business insert' => ['StereoNxImporter.php', "<?php function import() { \$db->exec('INSERT INTO invoices (id) VALUES (1)'); }", true];
        yield 'business update' => ['StereoNxImporter.php', "<?php function import() { \$db->exec('UPDATE supplier SET ic = \'x\''); }", true];
        yield 'business delete' => ['StereoNxImporter.php', "<?php function import() { \$db->exec('DELETE FROM invoice_items WHERE id = 1'); }", true];
        yield 'split quoted SQL' => ['StereoNxImporter.php', "<?php function import() { \$db->exec('INSERT ' . 'INTO invoices (id) VALUES (1)'); }", true];
        yield 'heredoc SQL' => ['StereoNxImporter.php', "<?php function import() { \$sql = <<<SQL\nUPDATE invoices SET status = 'sent'\nSQL; }", true];
        yield 'comment only' => ['StereoNxImporter.php', "<?php // INSERT INTO invoices\n/* UPDATE supplier SET ic = 'x' */", false];
        yield 'read-only lock' => ['StereoNxImporter.php', "<?php function import() { \$db->exec('SELECT id FROM supplier FOR UPDATE'); }", false];
        yield 'source map put' => [self::IMPORT_MAP_FILE, "<?php function put() { \$db->exec('INSERT INTO stereo_nx_import_map (id) VALUES (1)'); }", false];
        yield 'source map other method' => [self::IMPORT_MAP_FILE, "<?php function remove() { \$db->exec('DELETE FROM stereo_nx_import_map WHERE id = 1'); }", true];
        yield 'source map put other table' => [self::IMPORT_MAP_FILE, "<?php function put() { \$db->exec('INSERT INTO invoices (id) VALUES (1)'); }", true];
    }

    /** @return list<array{symbol:string,line:int,operation:string,table:string}> */
    private static function sqlWrites(string $source): array
    {
        $writes = [];
        $sql = '';
        $line = 1;
        $inHeredoc = false;
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                if ($token === ';' && !$inHeredoc) {
                    array_push($writes, ...self::writesInStatement($source, $sql, $line));
                    $sql = '';
                }
                continue;
            }
            if ($token[0] === T_START_HEREDOC) {
                $inHeredoc = true;
            } elseif ($token[0] === T_END_HEREDOC) {
                $inHeredoc = false;
            } elseif (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                if ($sql === '') {
                    $line = $token[2];
                }
                $sql .= $token[0] === T_CONSTANT_ENCAPSED_STRING
                    ? substr($token[1], 1, -1)
                    : $token[1];
            }
        }
        array_push($writes, ...self::writesInStatement($source, $sql, $line));
        return $writes;
    }

    /** @return list<array{symbol:string,line:int,operation:string,table:string}> */
    private static function writesInStatement(string $source, string $sql, int $line): array
    {
        preg_match_all(
            '/\b(?<operation>INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|DELETE\s+FROM|UPDATE)\s+`?(?<table>[a-z_][a-z_0-9]*)`?/i',
            $sql,
            $matches,
            PREG_SET_ORDER,
        );
        $writes = [];
        foreach ($matches as $match) {
            if (strtoupper($match['operation']) === 'UPDATE' && strtoupper($match['table']) === 'SELECT') {
                continue; // SELECT ... FOR UPDATE, followed by another read-only string.
            }
            $writes[] = [
                'symbol' => PhpSourceRegions::symbolAtLine($source, $line) ?? '(outside symbol)',
                'line' => $line,
                'operation' => strtoupper(preg_replace('/\s+/', ' ', $match['operation']) ?? $match['operation']),
                'table' => strtolower($match['table']),
            ];
        }
        return $writes;
    }

    /** @param array{symbol:string,line:int,operation:string,table:string} $write */
    private static function allowed(string $file, array $write): bool
    {
        return $file === self::IMPORT_MAP_FILE
            && $write['symbol'] === self::IMPORT_MAP_SYMBOL
            && $write['operation'] === 'INSERT INTO'
            && $write['table'] === 'stereo_nx_import_map';
    }

    private static function sourceDir(): string
    {
        return dirname(__DIR__, 2) . '/src/Service/Migration/StereoNx';
    }
}
