<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\RateLimit;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Klouzavá okna brzdy odchozí pošty (H-16).
 *
 * ⚠️ Tohle je JEDINÉ místo, které rozhoduje, kde okno začíná. Ani počítadlo
 * ({@see MailSendCounter}), ani brzda ({@see MailRateLimiter}) si začátek okna
 * nepočítají samy — jinak by stačilo, aby jedno z nich sklouzlo ke
 * kalendářnímu dni, a stavy se rozejdou.
 *
 * PROČ KLOUZAVÉ, NE KALENDÁŘNÍ: hosting počítá `sent_last_hour`
 * a `sent_last_day` klouzavě — půlnoc jeho počítadlo NENULUJE. Kdybychom
 * počítali kalendářně (`WHERE DATE(sent_at) = CURDATE()`), měli bychom
 * v 00:01 „volno" pro celý denní limit, zatímco u hostingu by pořád viselo
 * všechno, co odešlo večer. Výsledek: narazíme na 451 přesně ve chvíli, kdy
 * si myslíme, že máme rezervu — tedy v okamžiku, kdy brzda měla fungovat.
 *
 * Okno je vždy ABSOLUTNÍ počet sekund zpět, ne „stejná hodina včera": posun
 * letního času nesmí okno protáhnout ani zkrátit, protože hosting počítá
 * v UTC sekundách.
 */
final class MailRateLimitWindow
{
    public const HOUR = 'hour';
    public const DAY  = 'day';

    /** @var array<string,int> */
    private const SECONDS = [
        self::HOUR => 3600,
        self::DAY  => 86400,
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [self::HOUR, self::DAY];
    }

    public static function seconds(string $window): int
    {
        if (!isset(self::SECONDS[$window])) {
            throw new InvalidArgumentException("Neznámé okno brzdy: '{$window}'");
        }

        return self::SECONDS[$window];
    }

    /**
     * Začátek okna. Zprávy se počítají jako `sent_at > start` (ostrá
     * nerovnost — odeslání přesně na hraně už z okna vypadlo, stejně jako
     * u hostingu).
     */
    public static function start(DateTimeImmutable $now, string $window): DateTimeImmutable
    {
        return $now->modify('-' . self::seconds($window) . ' seconds');
    }

    /**
     * Kdy se okno uvolní, když je nejstarší zpráva v okně z času $oldest.
     * Přesně tenhle okamžik jde dát do fronty jako `not_before` — odhad
     * „zkus to za čtvrt hodiny" by frontu buď zbytečně brzdil, nebo pouštěl
     * do dalšího 451.
     */
    public static function freesAt(DateTimeImmutable $oldest, string $window): DateTimeImmutable
    {
        return $oldest->modify('+' . self::seconds($window) . ' seconds');
    }
}
