<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

interface TransferAutoPolicyInterface
{
    /** @return 'off'|'suggest'|'auto' */
    public function level(int $supplierId): string;
}
