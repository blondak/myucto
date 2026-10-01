<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tests\Support\OtherDocumentsPostingScenarios;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Bez dimenzí na dokladu a kartě majetku se ostatní pohledávky a závazky, zápočty
 * a majetek účtují BAJTOVĚ stejně jako před F4 (Účtování podle dimenzí). Očekávaný
 * výstup ({@see OtherDocumentsPostingScenarios}, soubor
 * Fixtures/posting-regression-f4-master-c844a173a.json) vznikl spuštěním týchž scénářů
 * nad kódem masteru c844a173a, dimenze firmy byly zapnuté.
 */
#[Group('integration')]
final class OtherDocumentsPostingRegressionTest extends TestCase
{
    public const FIXTURE = '/Fixtures/posting-regression-f4-master-c844a173a.json';

    public function testWithoutDimensionsPostingMatchesMaster(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $db->pdo()->beginTransaction();
        try {
            $actual = (new OtherDocumentsPostingScenarios($container))->run();
        } finally {
            $db->pdo()->rollBack();
            $db->close();
        }
        $expected = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . self::FIXTURE),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
        );
    }
}
