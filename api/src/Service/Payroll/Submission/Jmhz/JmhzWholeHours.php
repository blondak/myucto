<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Celé hodiny se zbytkem minut zaokrouhleným nahoru.
 *
 * Pokyny k vyplnění MH 1.4.14 (revize) předepisují pro 10273 (hodiny rizikové
 * práce, práce zdravotnického záchranáře a člena HZS podniku) „celé nezáporné
 * číslo (případný zbytek minut nižší než 60 se považuje za 1 hodinu)“. Katalog
 * kontrol 1.4.2.10 u kontroly 57 (10273 ≤ 10268) stejně tak zaokrouhluje
 * desetinné 10268 nahoru. Obě strany proto počítají tady, aby serializér
 * nezapsal hodnotu, kterou by vlastní kontrola odmítla.
 */
final class JmhzWholeHours
{
    public static function fromMillihours(int $millihours): int
    {
        if ($millihours < 0) {
            throw new \InvalidArgumentException('Počet hodin nesmí být záporný.');
        }

        return intdiv($millihours + 999, 1000);
    }

    /**
     * Škálovaná hodnota `[číslo, počet desetinných míst]` zaokrouhlená nahoru
     * na celé hodiny.
     *
     * @param array{0:int,1:int} $scaled
     * @return array{0:int,1:int}
     */
    public static function ceilScaled(array $scaled): array
    {
        [$value, $scale] = $scaled;
        if ($scale <= 0) {
            return [$value, 0];
        }
        $divisor = 10 ** $scale;
        $whole = intdiv($value, $divisor);
        if ($value > 0 && $value % $divisor !== 0) {
            ++$whole;
        }

        return [$whole, 0];
    }
}
