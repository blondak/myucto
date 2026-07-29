<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IdokladBankTransactionImporter;
use PHPUnit\Framework\TestCase;

final class IdokladBankMovementAmountTest extends TestCase
{
    public function testEntryIsPositive(): void
    {
        self::assertSame(1250.5, IdokladBankTransactionImporter::movementAmount([
            'MovementType' => 1,
            'Prices' => ['TotalWithVat' => 1250.5],
        ]));
    }

    public function testIssueIsNegative(): void
    {
        self::assertSame(-1250.5, IdokladBankTransactionImporter::movementAmount([
            'MovementType' => -1,
            'Prices' => ['TotalWithVat' => 1250.5],
        ]));
    }
}
