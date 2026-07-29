<?php

declare(strict_types=1);

namespace MyInvoice\Service\Penalty;

use DateTimeImmutable;

/**
 * Zdroj repo sazby ČNB pro výpočet úroku z prodlení. Umožňuje testovat
 * {@see PenaltyInterestCalculator} bez databáze (fake provider).
 */
interface RepoRateProvider
{
    /** Repo sazba (% p.a.) platná k danému dni, nebo NULL když není nastavena. */
    public function rateOn(DateTimeImmutable $date): ?float;
}
