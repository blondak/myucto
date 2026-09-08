<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/** Přenositelný klíč pohybu; tenantový rozsah vynucuje databáze samostatně. */
final class BankExternalImportIdentity
{
    public static function email(string $sourceReference): string
    {
        // Message-ID nebo fallback hash nesmí záviset na ID místní IMAP konfigurace.
        $message = preg_replace('/^imap-[0-9]+:/', '', $sourceReference);
        if ($message === null || $message === '') {
            throw new \InvalidArgumentException('Chybí identita bankovního avíza.');
        }
        return 'email:' . hash('sha256', $message);
    }

    public static function idoklad(int $movementId): string
    {
        if ($movementId < 1) {
            throw new \InvalidArgumentException('Neplatná externí identita bankovního pohybu.');
        }
        return 'idoklad:' . $movementId;
    }

    public static function emailMonth(string $account, ?string $bankCode, string $currency, string $month): string
    {
        return 'email-month:' . hash('sha256', $account . '|' . ($bankCode ?? '') . '|' . $currency . '|' . $month);
    }

    public static function idokladMonth(int $accountId, string $month): string
    {
        if ($accountId < 1 || preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $month) !== 1) {
            throw new \InvalidArgumentException('Neplatná identita měsíčního výpisu.');
        }
        return 'idoklad-month:' . $accountId . ':' . $month;
    }
}
