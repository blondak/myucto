<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Travel;

enum TravelVehicleKind: string
{
    case CAR = 'car';
    case SINGLE_TRACK = 'single_track';

    /** Klíč sazby základní náhrady v rulesetu cestovních náhrad. */
    public function basicCompensationParameter(): string
    {
        return match ($this) {
            self::CAR => 'vehicle.basic_compensation.car_per_km',
            self::SINGLE_TRACK => 'vehicle.basic_compensation.single_track_per_km',
        };
    }
}
