<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Travel;

use InvalidArgumentException;

/**
 * Jedna položka vyúčtování pracovní cesty. Doložený výdaj nese částku v haléřích,
 * jízda soukromým vozidlem nese vzdálenost v metrech, spotřebu v mililitrech na
 * 100 km a druh paliva; peněžní hodnotu z nich počítá až kalkulátor z rulesetu.
 */
final readonly class TravelExpenseItem
{
    public function __construct(
        public TravelExpenseItemKind $kind,
        public string $spentOn,
        public string $description,
        public ?int $amountMinor = null,
        public bool $documented = true,
        public ?string $documentReference = null,
        public ?TravelVehicleKind $vehicleKind = null,
        public ?int $distanceMetres = null,
        public ?int $consumptionMlPer100Km = null,
        public ?TravelFuelKind $fuelKind = null,
        public ?int $documentedFuelPriceMinor = null,
        public ?int $id = null,
    ) {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $spentOn);
        if ($parsed === false || $parsed->format('Y-m-d') !== $spentOn) {
            throw new InvalidArgumentException('Datum výdaje musí být ve tvaru YYYY-MM-DD.');
        }
        if (trim($description) === '' || mb_strlen($description) > 190) {
            throw new InvalidArgumentException('Popis položky vyúčtování není platný.');
        }
        if ($kind === TravelExpenseItemKind::PRIVATE_VEHICLE) {
            $this->assertPrivateVehicle();
            return;
        }
        if ($amountMinor === null || $amountMinor < 0) {
            throw new InvalidArgumentException('Doložený výdaj musí mít nezápornou částku.');
        }
        if ($vehicleKind !== null
            || $distanceMetres !== null
            || $consumptionMlPer100Km !== null
            || $fuelKind !== null
            || $documentedFuelPriceMinor !== null
        ) {
            throw new InvalidArgumentException(
                'Údaje o vozidle patří jen k položce jízdy soukromým vozidlem.',
            );
        }
    }

    private function assertPrivateVehicle(): void
    {
        if ($this->amountMinor !== null) {
            throw new InvalidArgumentException(
                'Jízda soukromým vozidlem se počítá z ujetých kilometrů, ne ze zadané částky.',
            );
        }
        if ($this->vehicleKind === null || $this->fuelKind === null) {
            throw new InvalidArgumentException('Jízda soukromým vozidlem vyžaduje druh vozidla i paliva.');
        }
        if ($this->distanceMetres === null || $this->distanceMetres <= 0) {
            throw new InvalidArgumentException('Ujetá vzdálenost musí být kladná.');
        }
        if ($this->consumptionMlPer100Km === null || $this->consumptionMlPer100Km <= 0) {
            throw new InvalidArgumentException('Průměrná spotřeba musí být kladná.');
        }
        if ($this->documentedFuelPriceMinor !== null && $this->documentedFuelPriceMinor <= 0) {
            throw new InvalidArgumentException('Doložená cena pohonné hmoty musí být kladná.');
        }
    }
}
