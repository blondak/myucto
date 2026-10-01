<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tests\Support\PostingRegressionScenarios;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Bez nastavení produktu, kategorie a účtu položky se účtuje BAJTOVĚ stejně jako před
 * F1 (Účtování podle dimenzí). Očekávaný výstup ({@see PostingRegressionScenarios},
 * soubor Fixtures/posting-regression-master-307179b4e.json) vznikl spuštěním týchž
 * scénářů nad kódem masteru 307179b4e: vydaná faktura, dobropis, cizí měna, sleva
 * z hlavičky, prodej majetku, vyúčtování proformy s odpočtem § 37a, přijatá faktura
 * s druhem výdaje, pořízení DHM, nedaňový náklad a razítkování dimenzí. Položky jsou
 * navázané na kartu a kategorii bez účtu a dimenzí.
 */
#[Group('integration')]
final class PostingRegressionMasterTest extends TestCase
{
    public function testWithoutProductSettingsPostingMatchesMaster(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $db->pdo()->beginTransaction();
        try {
            $actual = (new PostingRegressionScenarios($container))->run();
        } finally {
            $db->pdo()->rollBack();
            $db->close();
        }
        $expected = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/posting-regression-master-307179b4e.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (!isset($actual['foreign_currency'])) {
            unset($expected['foreign_currency']);   // instance bez měny EUR
        }
        self::assertSame(
            json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
        );
    }
}
