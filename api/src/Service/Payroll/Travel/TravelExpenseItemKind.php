<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Travel;

enum TravelExpenseItemKind: string
{
    case TRANSPORT = 'transport';
    case ACCOMMODATION = 'accommodation';
    case INCIDENTAL = 'incidental';
    case PRIVATE_VEHICLE = 'private_vehicle';
}
