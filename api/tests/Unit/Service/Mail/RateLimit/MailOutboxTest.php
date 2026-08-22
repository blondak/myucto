<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail\RateLimit;

use DateTimeImmutable;
use MyInvoice\Service\Mail\RateLimit\MailOutbox;
use MyInvoice\Service\Mail\RateLimit\MailRecipientBatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Fronta odložených zpráv — H-16, bod „odloží se, NEZAHODÍ".
 *
 * Testuje se tvar rozhodnutí, ne SQL: schéma hlídá CHECK v migraci 1520.
 * Podstatné je, že fronta sama NEOPRAVUJE překročený počet příjemců —
 * u toho je odmítnutí trvalé, takže odložit takovou zprávu znamená ztratit
 * ji o hodinu později a ještě si myslet, že je ve frontě.
 */
final class MailOutboxTest extends TestCase
{
    private function outbox(): MailOutbox
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné.');
        }

        // Prázdná paměťová databáze BEZ tabulky mail_outbox: kontrolované
        // odmítnutí musí přijít dřív, než se vůbec sáhne na SQL.
        return new MailOutbox(new \PDO('sqlite::memory:'), new NullLogger());
    }

    public function testQueueRefusesMessageOverTheHardRecipientLimit(): void
    {
        $now = new DateTimeImmutable('2026-03-10 10:00:00');

        self::assertNull(
            $this->outbox()->enqueue(
                $now,
                $now->modify('+1 hour'),
                'invoice_reminder',
                'cs',
                MailRecipientBatcher::HARD_MAX_RECIPIENTS + 1,
                'hour',
                ['to' => []],
            ),
            'Zpráva nad 100 příjemců se nesmí dostat ani do fronty — hosting ji odmítne TRVALE.',
        );
    }

    public function testPendingCountDegradesInsteadOfThrowingWithoutTable(): void
    {
        // Instalace před migrací 1520 nesmí kvůli diagnostice spadnout —
        // brzda je pojistka, ne bezpečnostní kontrola.
        self::assertSame(0, $this->outbox()->pendingCount());
    }
}
