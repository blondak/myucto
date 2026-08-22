<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\RateLimit;

use DateTimeImmutable;

/**
 * Počítadlo odeslaných ZPRÁV (ne příjemců) pro klouzavá okna brzdy.
 *
 * Rozhraní existuje kvůli testovatelnosti: chování na přelomu půlnoci se musí
 * dát ověřit bez databáze, ale obě implementace MUSÍ počítat okno přes
 * {@see MailRateLimitWindow}, ne vlastním výpočtem.
 */
interface MailSendCounterInterface
{
    /**
     * Kolik ZPRÁV odešlo po okamžiku $from (ostrá nerovnost).
     *
     * ⚠️ Jednotka je zpráva = SMTP transakce. Faktura rozeslaná padesáti
     * odběratelům v jedné zprávě přispěje 1, ne 50.
     */
    public function sentSince(DateTimeImmutable $from): int;

    /**
     * Nejstarší zpráva v okně, nebo null když je okno prázdné. Z ní se počítá
     * přesný okamžik, kdy se okno zase uvolní.
     */
    public function oldestSince(DateTimeImmutable $from): ?DateTimeImmutable;

    /** Zaznamenej JEDNU odeslanou zprávu. */
    public function record(
        DateTimeImmutable $at,
        int $recipients,
        string $template,
        ?string $emailProfile = null,
    ): void;
}
