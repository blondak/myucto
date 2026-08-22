<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail\RateLimit;

use MyInvoice\Service\Mail\RateLimit\MailRecipientBatcher;
use PHPUnit\Framework\TestCase;

/**
 * H-16 — jediné TVRDÉ pravidlo hostingu: nejvýš 100 příjemců na zprávu.
 *
 * Nad sto je odmítnutí TRVALÉ. Fronta tady nepomůže — zpráva se ztratí,
 * a ztratí se přesně u toho provozu, kvůli kterému limity vůbec řešíme:
 * u hromadných upomínek a rozesílek účetní kanceláře. Rozdělit dávku proto
 * musí aplikace, a musí to udělat PŘED odesláním, ne v reakci na chybu.
 */
final class MailRecipientBatcherTest extends TestCase
{
    /** @return list<string> */
    private function addresses(int $n, string $prefix = 'klient'): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = sprintf('%s%03d@example.test', $prefix, $i);
        }

        return $out;
    }

    public function testBatchOfTwoHundredFiftyRecipientsBecomesThreeMessages(): void
    {
        $batches = MailRecipientBatcher::split($this->addresses(250), [], []);

        self::assertCount(3, $batches, 'Dávka 250 příjemců musí odejít jako TŘI zprávy, ne jako jedna.');
        self::assertSame([100, 100, 50], array_map(
            static fn (array $b): int => MailRecipientBatcher::envelopeSize($b['to'], $b['cc'], $b['bcc']),
            $batches,
        ));

        // Nikdo se neztratil a nikdo nedostal zprávu dvakrát.
        $all = array_merge(...array_map(static fn (array $b): array => $b['to'], $batches));
        self::assertSame($this->addresses(250), $all);
        self::assertCount(250, array_unique($all));
    }

    public function testNoBatchEverExceedsTheHardLimit(): void
    {
        foreach ([1, 99, 100, 101, 250, 999] as $count) {
            foreach (MailRecipientBatcher::split($this->addresses($count), [], []) as $batch) {
                self::assertLessThanOrEqual(
                    MailRecipientBatcher::HARD_MAX_RECIPIENTS,
                    MailRecipientBatcher::envelopeSize($batch['to'], $batch['cc'], $batch['bcc']),
                    "Dávka {$count} příjemců vyrobila zprávu nad tvrdý strop — ta by se ztratila natrvalo.",
                );
            }
        }
    }

    public function testFiftyRecipientsStayInASingleMessage(): void
    {
        $batches = MailRecipientBatcher::split($this->addresses(50), [], []);

        self::assertCount(1, $batches, 'Padesát příjemců se do jedné zprávy vejde — dělit je znamená brzdit pětkrát dřív.');
        self::assertCount(50, $batches[0]['to']);
    }

    public function testCopiesCountTowardsTheEnvelopeLimit(): void
    {
        // Hosting vidí RCPT TO, ne hlavičky — kopie dodavateli do stropu patří.
        $batches = MailRecipientBatcher::split($this->addresses(99), ['ucetni@example.test'], ['archiv@example.test']);

        self::assertCount(2, $batches);
        self::assertSame(100, MailRecipientBatcher::envelopeSize(
            $batches[0]['to'],
            $batches[0]['cc'],
            $batches[0]['bcc'],
        ));
        self::assertSame(['archiv@example.test'], $batches[1]['bcc']);
    }

    public function testConfiguredLimitCanOnlyGoDownNeverUp(): void
    {
        // Konfigurace smí brzdu přitvrdit, ne povolit — nad 100 je odmítnutí
        // trvalé a žádná hodnota v cfg to nezmění.
        self::assertSame(100, MailRecipientBatcher::clamp(500));
        self::assertSame(100, MailRecipientBatcher::clamp(100));
        self::assertSame(25, MailRecipientBatcher::clamp(25));
        // Nula/záporná hodnota je překlep v cfg, ne pokyn „neposílej nic".
        self::assertSame(100, MailRecipientBatcher::clamp(0));
        self::assertSame(100, MailRecipientBatcher::clamp(-5));

        self::assertCount(10, MailRecipientBatcher::split($this->addresses(250), [], [], 25));
    }

    public function testEmptyRecipientListStaysOneEmptyBatch(): void
    {
        // Prázdný seznam řeší volající (Mailer to hlásí jako chybu) — batcher
        // z něj nesmí udělat nulu zpráv ani spadnout.
        self::assertSame([['to' => [], 'cc' => [], 'bcc' => []]], MailRecipientBatcher::split([], [], []));
    }
}
