<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

final class SuggestOnlyTransferPolicy implements TransferAutoPolicyInterface
{
    public function level(int $supplierId): string
    {
        return 'suggest';
    }
}
