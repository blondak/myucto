<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Jeden převzatý pracovní vztah i s osobou, jak ho čtečka zdroje předává zápisu.
 * Osoba se souběžnými vztahy přijde vícekrát; zápis její údaje doplní jen poprvé.
 */
final readonly class PayrollTakeoverRecord
{
    public function __construct(
        public PayrollTakeoverPerson $person,
        public PayrollTakeoverEmployment $employment,
    ) {}
}
