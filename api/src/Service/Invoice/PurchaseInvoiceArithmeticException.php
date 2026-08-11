<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * FR4 (vendor audit 2026-08) — základ + DPH neodpovídá uložené hlavičce
 * přijaté faktury (nebo základ + DPH neodpovídá uloženému celku). Za normálních
 * okolností to nemůže nastat — {@see PurchaseInvoiceCalculator::recompute()} odvozuje
 * hlavičku ze STEJNÉHO průchodu položkami, který persistuje. Výjimka je bezpečnostní
 * síť proti budoucí regresi (nová cesta zápisu, která tenhle krok obejde), ne proti
 * dnešnímu chování — nejrizikovější je doklad s `vat_overrides` (§73 ZDPH), kde se
 * zaokrouhlovací reziduum rozděluje na nejsilnější řádek dané sazby.
 *
 * `extends \InvalidArgumentException`, aby ji beze změny odchytily existující bloky
 * v Create/UpdatePurchaseInvoiceAction (`catch (\InvalidArgumentException $e)` →
 * 400 `integrity_violation`).
 */
final class PurchaseInvoiceArithmeticException extends \InvalidArgumentException
{
}
