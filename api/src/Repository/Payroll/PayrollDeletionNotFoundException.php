<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Mazaný mzdový záznam v tomto tenantu neexistuje. Volající to překlápí na 404,
 * aby cizí tenant nezjistil ani to, jestli id existuje.
 */
final class PayrollDeletionNotFoundException extends \RuntimeException
{
}
