<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\RateLimit;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Počítadlo odeslaných zpráv nad tabulkou `mail_send_log` (migrace 1520).
 *
 * ⚠️ Dotaz je ZÁMĚRNĚ `sent_at > :from` s hranicí spočítanou v PHP přes
 * {@see MailRateLimitWindow}, ne `WHERE DATE(sent_at) = CURDATE()` ani
 * `sent_at >= CURDATE()`. Kalendářní varianta by se o půlnoci vynulovala,
 * zatímco počítadlo hostingu běží dál — a 451 by přišlo přesně ve chvíli,
 * kdy si myslíme, že máme volno. Tvar dotazu hlídá
 * `MailSlidingWindowTest::testCounterQueryUsesSlidingWindowNotCalendarDay`.
 *
 * Chybějící tabulka (instalace před migrací) i nedostupná databáze degradují
 * na „nevím" → 0 zpráv v okně. Brzda je pojistka, ne bezpečnostní kontrola:
 * její výpadek nesmí zastavit odesílání pošty. Nejhorší důsledek je, že se
 * o brzdění postará hosting sám přes 451, což je stav před H-16.
 */
final class MailSendCounter implements MailSendCounterInterface
{
    /** Formát pro DATETIME(3) — bez milisekund by dávka v jedné sekundě splynula. */
    public const SQL_FORMAT = 'Y-m-d H:i:s.v';

    public function __construct(private readonly PDO $pdo) {}

    public function sentSince(DateTimeImmutable $from): int
    {
        try {
            $stmt = $this->pdo->prepare(self::COUNT_SQL);
            $stmt->execute(['from' => $from->format(self::SQL_FORMAT)]);

            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    public function oldestSince(DateTimeImmutable $from): ?DateTimeImmutable
    {
        try {
            $stmt = $this->pdo->prepare(self::OLDEST_SQL);
            $stmt->execute(['from' => $from->format(self::SQL_FORMAT)]);
            $value = $stmt->fetchColumn();
        } catch (Throwable) {
            return null;
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    public function record(
        DateTimeImmutable $at,
        int $recipients,
        string $template,
        ?string $emailProfile = null,
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO mail_send_log (sent_at, template, email_profile, recipients)
                 VALUES (:sent_at, :template, :profile, :recipients)'
            );
            $stmt->execute([
                'sent_at'    => $at->format(self::SQL_FORMAT),
                'template'   => mb_substr($template, 0, 64),
                'profile'    => $emailProfile !== null ? mb_substr($emailProfile, 0, 64) : null,
                // Clamp na tvrdý strop hostingu — CHECK v migraci by jinak
                // shodil odeslání, které už proběhlo, a to je horší než
                // nepřesný diagnostický údaj.
                'recipients' => max(0, min($recipients, MailRecipientBatcher::HARD_MAX_RECIPIENTS)),
            ]);
        } catch (Throwable) {
            // Neúspěšný zápis počítadla nesmí shodit už odeslaný e-mail.
        }
    }

    /**
     * Úklid — okno je nejvýš den, takže cokoli staršího než $days dnů je
     * jen historie pro rozbor spotřeby kvóty.
     */
    public function prune(DateTimeImmutable $now, int $days = 3): int
    {
        $days = max(1, $days);
        try {
            $stmt = $this->pdo->prepare('DELETE FROM mail_send_log WHERE sent_at < :before');
            $stmt->execute([
                'before' => $now->modify('-' . $days . ' days')->format(self::SQL_FORMAT),
            ]);

            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Dotazy jsou konstanty, aby na ně šlo posvítit guardem — přepsání na
     * kalendářní den je tichá regrese, kterou by běžný test neodhalil
     * (v poledne se obě varianty chovají stejně).
     */
    public const COUNT_SQL = 'SELECT COUNT(*) FROM mail_send_log WHERE sent_at > :from';

    public const OLDEST_SQL = 'SELECT MIN(sent_at) FROM mail_send_log WHERE sent_at > :from';
}
