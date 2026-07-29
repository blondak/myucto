<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

interface LlmClassifierInterface
{
    /** @param array<string,mixed> $sanitizedTx @param list<array<string,string>> $fewShot @return array<string,mixed> */
    public function classifyBankTransaction(int $supplierId, array $sanitizedTx, array $fewShot): array;

    /** @param array<string,mixed> $sanitizedPf @param list<array<string,string>> $fewShot @param list<array<string,mixed>> $tenantCategories @return array<string,mixed> */
    public function suggestPurchasePosting(int $supplierId, array $sanitizedPf, array $fewShot, array $tenantCategories): array;
}
