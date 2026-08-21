<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail\RateLimit;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Mail\RateLimit\MailRateLimiter;
use MyInvoice\Service\Mail\RateLimit\MailRateLimitWindow;
use MyInvoice\Service\Mail\RateLimit\MailSendCounter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * H-16 — brzda musí počítat KLOUZAVĚ a v jednotce ZPRÁVA.
 *
 * Dvě chyby, které by tenhle test měl chytat a bez kterých je celá brzda
 * horší než žádná (dává falešný pocit rezervy):
 *
 *  1. **Kalendářní okno místo klouzavého.** `WHERE DATE(sent_at) = CURDATE()`
 *     se o půlnoci vynuluje, kdežto počítadlo hostingu běží dál. V 00:01
 *     bychom si mysleli, že máme volný celý denní limit, a narazili na 451
 *     přesně tehdy, kdy měla brzda fungovat. Test to dokazuje tak, že vedle
 *     sebe postaví obě počítání a TRVÁ na tom, že se liší — kdyby brzda
 *     počítala kalendářně, tvrzení „odloženo" padne.
 *  2. **Počítání příjemců místo zpráv.** Hosting počítá SMTP transakce;
 *     jedna upomínka padesáti odběratelům je jedno odeslání. Kdo počítá
 *     řádky `To:`, brzdí padesátkrát dřív, než je potřeba.
 */
final class MailSlidingWindowTest extends TestCase
{
    private function limiter(InMemoryMailSendCounter $counter, int $perHour, int $perDay): MailRateLimiter
    {
        return new MailRateLimiter(
            new Config(['smtp' => ['rate_limit' => [
                'enabled'      => true,
                'per_hour'     => $perHour,
                'per_day'      => $perDay,
                'warn_percent' => 90,
            ]]]),
            $counter,
            new NullLogger(),
        );
    }

    public function testDayWindowSlidesAcrossMidnightInsteadOfResetting(): void
    {
        $counter = new InMemoryMailSendCounter();
        // Večerní dávka upomínek — 800 zpráv ve 23:30.
        $counter->seed(new DateTimeImmutable('2026-03-10 23:30:00'), 800);

        $justAfterMidnight = new DateTimeImmutable('2026-03-11 00:01:00');

        // Kontrolní důkaz, že test nekontroluje nic samozřejmého: kalendářní
        // počítání by v 00:01 vidělo NULU odeslaných zpráv a brzdu by nesepnulo.
        self::assertSame(0, $counter->sentSameCalendarDay($justAfterMidnight));
        self::assertSame(
            800,
            $counter->sentSince(MailRateLimitWindow::start($justAfterMidnight, MailRateLimitWindow::DAY)),
            'Klouzavé denní okno musí večerní dávku po půlnoci pořád vidět.',
        );

        $decision = $this->limiter($counter, 160, 800)->decide($justAfterMidnight);

        self::assertTrue(
            $decision->isDeferred(),
            'Půlnoc denní limit NENULUJE — hosting počítá klouzavě a my musíme taky.',
        );
        self::assertSame(MailRateLimitWindow::DAY, $decision->window);
        self::assertSame(800, $decision->sentLastDay);
        self::assertStringStartsWith('451', $decision->smtpResponse());
        self::assertSame('deferred', $decision->toArray()['over_limit_action']);
    }

    public function testDayWindowReleasesExactlyOneDayAfterOldestMessage(): void
    {
        $counter = new InMemoryMailSendCounter();
        $sentAt = new DateTimeImmutable('2026-03-10 23:30:00');
        $counter->seed($sentAt, 800);

        // Uvolnění se počítá z nejstarší zprávy v okně, ne paušálním odhadem.
        $decision = $this->limiter($counter, 160, 800)->decide(new DateTimeImmutable('2026-03-11 00:01:00'));
        self::assertNotNull($decision->retryAt);
        self::assertSame(
            $sentAt->modify('+1 day')->format('Y-m-d H:i:s'),
            $decision->retryAt->format('Y-m-d H:i:s'),
        );

        // O den a minutu později už okno prázdné je.
        $later = $this->limiter($counter, 160, 800)->decide(new DateTimeImmutable('2026-03-11 23:31:00'));
        self::assertFalse($later->isDeferred());
        self::assertSame(0, $later->sentLastDay);
    }

    public function testHourWindowSlidesToo(): void
    {
        $counter = new InMemoryMailSendCounter();
        $counter->seed(new DateTimeImmutable('2026-03-10 10:00:00'), 160);

        self::assertTrue(
            $this->limiter($counter, 160, 800)->decide(new DateTimeImmutable('2026-03-10 10:30:00'))->isDeferred(),
            'Hodinové okno je klouzavé — v 10:30 je dávka z 10:00 pořád uvnitř.',
        );
        self::assertFalse(
            $this->limiter($counter, 160, 800)->decide(new DateTimeImmutable('2026-03-10 11:01:00'))->isDeferred(),
            'Hodinu a minutu po dávce už musí být volno.',
        );
    }

    public function testOneMessageToFiftyRecipientsCountsAsOneSend(): void
    {
        $counter = new InMemoryMailSendCounter();
        $limiter = $this->limiter($counter, 160, 800);
        $now = new DateTimeImmutable('2026-03-10 09:00:00');

        $limiter->recordSent($now, 50, 'invoice_reminder');

        self::assertSame(
            1,
            $counter->sentSince(MailRateLimitWindow::start($now->modify('+1 second'), MailRateLimitWindow::HOUR)),
            'Limit hostingu počítá ZPRÁVY. Padesát příjemců v jedné zprávě je jedno odeslání.',
        );
        self::assertSame(50, $counter->recipientTotal(), 'Počet příjemců se eviduje, ale jako diagnostika.');

        // A hlavně: padesátkrát menší spotřeba limitu než při počítání příjemců.
        $decision = $limiter->decide($now->modify('+1 second'));
        self::assertFalse($decision->isDeferred());
        self::assertSame(1, $decision->sentLastHour);
    }

    public function testWarningFiresBeforeTheBrakeEngages(): void
    {
        $counter = new InMemoryMailSendCounter();
        $counter->seed(new DateTimeImmutable('2026-03-10 10:00:00'), 145); // 90,6 % ze 160

        $decision = $this->limiter($counter, 160, 800)->decide(new DateTimeImmutable('2026-03-10 10:05:00'));

        self::assertFalse($decision->isDeferred(), 'Na 90 % se ještě posílá.');
        self::assertTrue($decision->warning, 'Na 90 % už musí jít varování správci — ne až po zastavení.');
        self::assertSame(MailRateLimitWindow::HOUR, $decision->window);
    }

    public function testDecisionPayloadMatchesHostingEventShape(): void
    {
        $counter = new InMemoryMailSendCounter();
        $counter->seed(new DateTimeImmutable('2026-03-10 10:00:00'), 10);

        $payload = $this->limiter($counter, 160, 800)
            ->decide(new DateTimeImmutable('2026-03-10 10:05:00'))
            ->toArray();

        // Hosting posílá `instance.mail_limit_*` s těmito poli. Stavy se s nimi
        // nesmí rozejít, takže musí jít porovnat kus po kuse.
        self::assertSame(
            ['sent_last_hour', 'sent_last_day', 'limit_hour', 'limit_day', 'percent', 'window', 'over_limit_action'],
            array_keys($payload),
        );
        self::assertContains($payload['window'], MailRateLimitWindow::all());
    }

    public function testSlidingWindowIsAbsoluteSecondsNotWallClock(): void
    {
        // Přechod na letní čas 2026: v ČR se v neděli 29. 3. ve 2:00 přeskočí
        // hodina. Okno musí zůstat 3 600 sekund, ne „stejná hodina na hodinách".
        $tz = new \DateTimeZone('Europe/Prague');
        $now = new DateTimeImmutable('2026-03-29 03:30:00', $tz);

        $start = MailRateLimitWindow::start($now, MailRateLimitWindow::HOUR);

        self::assertSame(3600, $now->getTimestamp() - $start->getTimestamp());
    }

    public function testBrakeDefaultsToManagedInstallationsOnly(): void
    {
        // Limity jsou vlastnost hostingu, ne aplikace. Self-hoster s vlastním
        // MTA nesmí po aktualizaci najednou váznout na 160 zprávách za hodinu.
        $counter = new InMemoryMailSendCounter();
        $counter->seed(new DateTimeImmutable('2026-03-10 10:00:00'), 5000);
        $now = new DateTimeImmutable('2026-03-10 10:30:00');

        $selfHosted = new MailRateLimiter(new Config([]), $counter, new NullLogger());
        self::assertFalse($selfHosted->enabled());
        self::assertFalse($selfHosted->decide($now)->isDeferred());

        $managed = new MailRateLimiter(new Config(['app' => ['managed' => true]]), $counter, new NullLogger());
        self::assertTrue($managed->enabled());
        self::assertTrue($managed->decide($now)->isDeferred());

        // Výchozí prahy musí ležet POD mezí hostingu (200/hod, 1 000/den),
        // jinak brzda nesepne dřív než jejich 451 a celý H-16 je k ničemu.
        self::assertLessThan(MailRateLimiter::HOSTING_LIMIT_HOUR, $managed->limitHour());
        self::assertLessThan(MailRateLimiter::HOSTING_LIMIT_DAY, $managed->limitDay());
        self::assertSame([], $managed->configurationWarnings());
    }

    public function testConfigurationAboveHostingLimitIsReported(): void
    {
        // Prahy nad mez hostingu brzdu fakticky vypínají — musí to být vidět,
        // protože jinak se to pozná až podle fronty na jejich straně.
        $limiter = new MailRateLimiter(
            new Config(['app' => ['managed' => true], 'smtp' => ['rate_limit' => [
                'per_hour' => 500,
                'per_day'  => 5000,
            ]]]),
            new InMemoryMailSendCounter(),
            new NullLogger(),
        );

        self::assertCount(2, $limiter->configurationWarnings());
    }

    public function testRecipientCapCannotBeRaisedByConfiguration(): void
    {
        $limiter = new MailRateLimiter(
            new Config(['smtp' => ['rate_limit' => ['max_recipients_per_message' => 500]]]),
            new InMemoryMailSendCounter(),
            new NullLogger(),
        );

        self::assertSame(100, $limiter->maxRecipientsPerMessage());
    }

    public function testCounterQueryUsesSlidingWindowNotCalendarDay(): void
    {
        // Guard nad SQL: v poledne se klouzavá i kalendářní varianta chovají
        // stejně, takže tichou regresi na CURDATE() by běžný test neodhalil.
        foreach ([MailSendCounter::COUNT_SQL, MailSendCounter::OLDEST_SQL] as $sql) {
            self::assertStringContainsString('sent_at > :from', $sql);
            self::assertStringNotContainsStringIgnoringCase('CURDATE', $sql);
            self::assertStringNotContainsStringIgnoringCase('DATE(sent_at)', $sql);
        }
    }
}
