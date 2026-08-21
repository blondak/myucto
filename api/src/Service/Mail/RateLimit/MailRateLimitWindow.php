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
     *
     * ⚠️ Posun jde přes `setTimestamp()`, NE přes `modify('-3600 seconds')`.
     * `modify()` počítá po nástěnných hodinách, takže na přechodu času dá
     * nesmysl: `2026-03-29 03:30 Europe/Prague` minus 3 600 sekund vrátí
     * TÝŽ okamžik (02:30 ten den neexistuje, PHP ho normalizuje zpět na 03:30)
     * a minus 86 400 sekund posune jen o 82 800 sekund. Okno by se tu noc
     * jednou za rok zkrátilo na nulu, respektive protáhlo o hodinu — a přesně
     * tehdy by brzda buď nesepnula, nebo sepla o hodinu dřív.
     *
     * Sub-sekundová část se posunem ztrácí. To je vědomé a bezpečné: hranice
     * se zaokrouhlí DOLŮ, takže okno je nejvýš o sekundu širší, nikdy užší.
     */
    public static function start(DateTimeImmutable $now, string $window): DateTimeImmutable
    {
        return $now->setTimestamp($now->getTimestamp() - self::seconds($window));
    }

    /**
     * Kdy se okno uvolní, když je nejstarší zpráva v okně z času $oldest.
     * Přesně tenhle okamžik jde dát do fronty jako `not_before` — odhad
     * „zkus to za čtvrt hodiny" by frontu buď zbytečně brzdil, nebo pouštěl
     * do dalšího 451.
     *
     * Zaokrouhluje se NAHORU (o sekundu, když měla zpráva sub-sekundovou
     * část), aby fronta nikdy nezkusila odeslat o chlup dřív, než se okno
     * doopravdy uvolní.
     */
    public static function freesAt(DateTimeImmutable $oldest, string $window): DateTimeImmutable
    {
        $ceil = $oldest->format('u') === '000000' ? 0 : 1;

        return $oldest->setTimestamp($oldest->getTimestamp() + self::seconds($window) + $ceil);
    }
}
