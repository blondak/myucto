<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Pokus o smazání záznamu, který se ještě musí uchovávat (§ 31, § 32 ZoÚ, § 35a ZDPH).
 *
 * Vlastní typ, ne obecná RuntimeException: akce ho mapuje na 422 (nesplněná podmínka
 * vstupu) místo 500 a hláška nese konkrétní datum nebo číslo jednací zadržení.
 */
final class RetentionViolationException extends \RuntimeException
{
}
