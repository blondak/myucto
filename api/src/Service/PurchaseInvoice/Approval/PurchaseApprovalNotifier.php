<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice\Approval;

/**
 * Doručení žádosti o schválení schvalovateli. V aplikaci e-mail
 * ({@see PurchaseApprovalMailer}); testy podstrčí záznamník, ať nic neodchází
 * a mají v ruce token z odkazu.
 */
interface PurchaseApprovalNotifier
{
    /** @param array<string,mixed> $row řádek z PurchaseInvoiceApprovalRepository */
    public function send(array $row, string $token, bool $isReminder): void;
}
