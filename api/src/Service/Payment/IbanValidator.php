<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment;

/**
 * IBAN (ISO 13616, mod-97) a BIC (ISO 9362) validace — bez závislostí, sdílené mezi
 * SEPA exportem (`SepaPaymentOrderWriter`), platebními příkazy a QR platbami.
 *
 * Pozn.: `BankAccountParser::isValidIban()` má vlastní kopii stejného algoritmu
 * (použitou jen k odfiltrování náhodných řetězců při parsování volného textu) —
 * zde jde o samostatnou, explicitně volanou validaci pro SEPA export.
 */
final class IbanValidator
{
    /** IBAN mod-97 kontrolní součet (ISO 13616). Vstup normalizuj přes normalize() dopředu. */
    public function isValid(string $iban): bool
    {
        $iban = $this->normalize($iban);
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        $remainder = 0;
        foreach (str_split($numeric) as $d) {
            $remainder = ($remainder * 10 + (int) $d) % 97;
        }
        return $remainder === 1;
    }

    /** BIC/SWIFT formát (8 nebo 11 znaků): 4 písmena banky + 2 písmena země + 2 alfanum + volitelně 3 alfanum pobočky. */
    public function isValidBic(string $bic): bool
    {
        $bic = strtoupper(trim($bic));
        return preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic) === 1;
    }

    /** Odstraní mezery a převede na velká písmena (vstupy z formulářů typicky obsahují mezery). */
    public function normalize(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }
}
