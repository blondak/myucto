<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\Import\IsdocToPurchaseInvoiceMapper;
use PHPUnit\Framework\TestCase;

final class InvoiceImportPurchaseDuplicateTest extends TestCase
{
    public function testPurchaseResultPropagatesMapperDuplicateForEditorRedirect(): void
    {
        $mapper = $this->createMock(IsdocToPurchaseInvoiceMapper::class);
        $mapper->expects(self::once())->method('map')->with(['id' => 'SYN-1'], 7, 11)
            ->willReturn([
                'purchase_invoice_id' => 42,
                'vendor_id' => 9,
                'vendor_created' => false,
                'duplicate' => true,
            ]);

        $service = (new \ReflectionClass(InvoiceImportService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(InvoiceImportService::class, 'purchaseMapper'))->setValue($service, $mapper);

        $result = (new \ReflectionMethod(InvoiceImportService::class, 'processPurchase'))
            ->invoke($service, ['id' => 'SYN-1'], 7, 11);

        self::assertSame('created', $result['status']);
        self::assertSame(42, $result['purchase_invoice_id']);
        self::assertTrue($result['duplicate']);
        self::assertSame('přijatá faktura již existuje', $result['reason']);
    }
}
