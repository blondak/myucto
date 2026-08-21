<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail\RateLimit;

use DateTimeImmutable;
use MyInvoice\Service\Mail\RateLimit\MailSendCounterInterface;

/**
 * Počítadlo bez databáze pro testy brzdy.
 *
 * ⚠️ Okno se tu ZÁMĚRNĚ nepočítá vlastním vzorcem — filtruje se přesně tím
 * `$from`, které dostane od {@see \MyInvoice\Service\Mail\RateLimit\MailRateLimiter}.
 * Kdyby si testovací dvojník počítal okno po svém, testoval by sám sebe
 * a klouzavost by neověřil.
 */
final class InMemoryMailSendCounter implements MailSendCounterInterface
{
    /** @var list<array{at:DateTimeImmutable,recipients:int,template:string,profile:?string}> */
    public array $sends = [];

    public function seed(DateTimeImmutable $at, int $count = 1, int $recipients = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->sends[] = [
                'at'         => $at,
                'recipients' => $recipients,
                'template'   => 'seed',
                'profile'    => null,
            ];
        }
    }

    public function sentSince(DateTimeImmutable $from): int
    {
        $n = 0;
        foreach ($this->sends as $send) {
            if ($send['at'] > $from) {
                $n++;
            }
        }

        return $n;
    }

    public function oldestSince(DateTimeImmutable $from): ?DateTimeImmutable
    {
        $oldest = null;
        foreach ($this->sends as $send) {
            if ($send['at'] > $from && ($oldest === null || $send['at'] < $oldest)) {
                $oldest = $send['at'];
            }
        }

        return $oldest;
    }

    public function record(
        DateTimeImmutable $at,
        int $recipients,
        string $template,
        ?string $emailProfile = null,
    ): void {
        $this->sends[] = [
            'at'         => $at,
            'recipients' => $recipients,
            'template'   => $template,
            'profile'    => $emailProfile,
        ];
    }

    /** Součet příjemců — jen pro kontrolu, že se NEPOUŽÍVÁ jako jednotka limitu. */
    public function recipientTotal(): int
    {
        return array_sum(array_column($this->sends, 'recipients'));
    }

    /**
     * Kalendářní počítadlo — přesně ta chyba, kterou H-16 zakazuje.
     * Slouží v testu k důkazu, že se sliding a kalendář na přelomu půlnoci
     * NEshodnou (jinak by test procházel s obojím a nekontroloval nic).
     */
    public function sentSameCalendarDay(DateTimeImmutable $now): int
    {
        $n = 0;
        foreach ($this->sends as $send) {
            if ($send['at']->format('Y-m-d') === $now->format('Y-m-d')) {
                $n++;
            }
        }

        return $n;
    }
}
