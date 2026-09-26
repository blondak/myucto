<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * `CtOsoba` z NEMPRI25.xsd — ošetřovaná osoba u ošetřovného a dlouhodobého
 * ošetřovného, dítě u otcovské a peněžité pomoci v mateřství.
 *
 * Rodné číslo i datum narození jsou v XSD nepovinné. Osoba vybraná z evidence
 * vyživovaných osob nese rodné číslo odhalené až při sestavení věty; osoba
 * zadaná ručně jen jméno a datum narození, protože rodné číslo se mimo
 * šifrovanou evidenci neukládá.
 */
final readonly class NempriPerson
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public ?string $birthNumber = null,
        public ?string $birthDate = null,
    ) {}
}
