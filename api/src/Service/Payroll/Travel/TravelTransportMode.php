<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Travel;

enum TravelTransportMode: string
{
    case PUBLIC_TRANSPORT = 'public_transport';
    case COMPANY_VEHICLE = 'company_vehicle';
    case PRIVATE_VEHICLE = 'private_vehicle';
    case OTHER = 'other';
}
