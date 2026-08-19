<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Tři skupiny kódů změny podle jednotné datové věty HOZ. Skupina je jediné,
 * co o kódech podklady doloženě říkají — význam jednotlivých písmen ne.
 */
enum HealthNotificationCodeGroup: string
{
    /** Vznik, změna a zánik zaměstnání: P, A, E, C, O, Q. */
    case Employment = 'employment';

    /** Kategorie, kde je plátcem stát: M, U, D, H, I, J, G, F, L, T, N, K, S, R, W, V. */
    case StateCategory = 'state_category';

    /** Opravy již podaných vět: X, Y, Z. */
    case Correction = 'correction';
}
