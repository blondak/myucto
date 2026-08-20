<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Submission\PayrollAgendaGroupCatalog;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SQL výraz musí zařazovat stejně jako PHP.
 *
 * Přehled podání filtruje a zobrazuje skupinu SQL výrazem, kdežto katalog ji
 * počítá v PHP. Kdyby se ty dva předpisy rozešly, panel by ukazoval jinou
 * skupinu, než podle které stránkuje — přesně ta třída chyby, kvůli které
 * klasifikace přestala poznávat ročníkové kódy. Test proto pouští ten samý
 * výraz proti MariaDB.
 */
#[Group('integration')]
final class PayrollAgendaGroupSqlParityTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->db = Bootstrap::buildApp()
                ->getContainer()
                ->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
    }

    public function testSqlClassificationMatchesPhp(): void
    {
        $codes = [
            'JMHZ25', 'JMHZ26', 'JMHZ_2030', 'JMHZ',
            'ELDP', 'OZUSPOJ', 'DZMH',
            'PREZEC26', 'REGZEC25', 'REGZEL26', 'REGZELDOPL25',
            'HOZ_2026', 'PPZ_2026', 'HOZ', 'PPZ', 'HEALTH-PPZ',
            ' jmhz25 ', 'vymyslena', 'JMHZX', 'REGZELDOPLXX',
        ];

        $expression = PayrollAgendaGroupCatalog::sqlExpression('probe.code');
        $placeholders = implode(
            ' UNION ALL ',
            array_fill(0, count($codes), 'SELECT ? AS code'),
        );
        $statement = $this->db->pdo()->prepare(
            'SELECT probe.code, ' . $expression . ' AS agenda_group'
            . ' FROM (' . $placeholders . ') probe',
        );
        $statement->execute($codes);

        $sqlGroups = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $sqlGroups[(string) $row['code']] = (string) $row['agenda_group'];
        }

        $phpGroups = [];
        foreach ($codes as $code) {
            $phpGroups[$code] = PayrollAgendaGroupCatalog::groupOf($code);
        }
        ksort($phpGroups);
        ksort($sqlGroups);

        self::assertSame(
            $phpGroups,
            $sqlGroups,
            'SQL a PHP klasifikace agend se rozešly.',
        );
        self::assertContains(
            PayrollAgendaGroupCatalog::GROUP_OTHER,
            $sqlGroups,
            'Sada musí obsahovat i nezařaditelný kód, jinak nekontroluje ELSE větev.',
        );
    }
}
