<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Shared\MigrationHomeCurrency;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationHomeCurrencyTest extends TestCase
{
    private PDO $pdo;
    private MigrationHomeCurrency $currency;

    protected function setUp(): void
    {
        $this->pdo = new \Pdo\Sqlite('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY, supplier_id INT, code TEXT, is_default INT)');
        $this->pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, default_currency_id INT)');
        $db = new Connection(new Config([]));
        (new \ReflectionProperty($db, 'pdo'))->setValue($db, $this->pdo);
        $this->currency = new MigrationHomeCurrency($db);
    }

    public function testDefaultCzkOfTheCompanyWins(): void
    {
        $this->pdo->exec("INSERT INTO currencies VALUES (1, 5, 'EUR', 1), (2, 5, 'CZK', 0), (3, 5, 'CZK', 1), (4, 6, 'CZK', 1)");
        self::assertSame(3, $this->currency->id(5));
    }

    public function testCompanyWithoutCzkFallsBackToItsDefaultCurrency(): void
    {
        $this->pdo->exec("INSERT INTO currencies VALUES (1, 5, 'EUR', 1)");
        $this->pdo->exec('INSERT INTO supplier VALUES (5, 1)');
        self::assertSame(1, $this->currency->id(5));
    }
}
