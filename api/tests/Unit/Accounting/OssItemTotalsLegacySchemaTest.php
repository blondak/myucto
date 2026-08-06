<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Zpětná kompatibilita OSS-8: instance BEZ OSS schématu (chybí migrace 0137) se
 * nesmí o účtování OSS ani otřít.
 *
 * Test nesahá na databázi vůbec — Connection je mock, kterému `pdo()` NENÍ nastavené.
 * Kdyby guard `hasColumn()` zmizel, helper by se pokusil připravit dotaz nad neexistujícím
 * sloupcem a test spadne (mock vrátí null → TypeError). To je právě ta situace, kterou
 * má guard v produkci na staré instanci zabránit.
 *
 * PostingService se skládá přes newInstanceWithoutConstructor: závislosti krom Connection
 * tahle větev vůbec nepotřebuje a část z nich jsou `final` třídy mimo bypass-finals
 * allowlist, takže by se mockovat nedaly.
 */
final class OssItemTotalsLegacySchemaTest extends TestCase
{
    public function testReturnsZerosWhenOssColumnIsMissing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('hasColumn')->willReturn(false);
        $connection->expects(self::never())->method('pdo');

        self::assertSame([0.0, 0.0], $this->ossItemTotals($connection, 1, 1.0));
        self::assertSame([0.0, 0.0], $this->ossItemTotals($connection, 1, 25.5), 'Ani cizoměnový doklad nesmí sáhnout do OSS sloupců.');
    }

    /** @return array{0:float,1:float} */
    private function ossItemTotals(Connection $connection, int $invoiceId, float $rate): array
    {
        $class = new ReflectionClass(PostingService::class);
        $service = $class->newInstanceWithoutConstructor();
        $class->getProperty('db')->setValue($service, $connection);

        /** @var array{0:float,1:float} $result */
        $result = $class->getMethod('ossItemTotals')->invoke($service, $invoiceId, $rate);

        return $result;
    }
}
