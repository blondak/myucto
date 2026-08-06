<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Travel;

enum TravelFuelKind: string
{
    case PETROL_95 = 'petrol_95';
    case PETROL_98 = 'petrol_98';
    case DIESEL = 'diesel';
    case ELECTRICITY = 'electricity';

    /** Klíč průměrné ceny pohonné hmoty v rulesetu cestovních náhrad. */
    public function averagePriceParameter(): string
    {
        return match ($this) {
            self::PETROL_95 => 'fuel.average_price.petrol_95_per_litre',
            self::PETROL_98 => 'fuel.average_price.petrol_98_per_litre',
            self::DIESEL => 'fuel.average_price.diesel_per_litre',
            self::ELECTRICITY => 'fuel.average_price.electricity_per_kwh',
        };
    }
}
