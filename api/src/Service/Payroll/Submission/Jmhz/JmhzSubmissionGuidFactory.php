<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * UUIDv7 podle RFC 9562: 48 bitů času v milisekundách, verze 7, varianta 10
 * a zbytek náhodně. Pravidla podání JMHZ (kap. 9) navíc výslovně vyžadují
 * číslici 7 na první pozici třetí skupiny — to je právě pole verze, takže
 * korektní UUIDv7 tuhle podmínku splňuje ze své definice.
 *
 * Generátor je oddělený od serializéru schválně: serializér musí zůstat
 * deterministický a GUIDy do něj vstupují zvenčí.
 */
final class JmhzSubmissionGuidFactory
{
    public function next(): string
    {
        $bytes = random_bytes(16);
        $milliseconds = (int) floor(microtime(true) * 1000);
        for ($index = 5; $index >= 0; $index--) {
            $bytes[$index] = chr($milliseconds & 0xFF);
            $milliseconds >>= 8;
        }
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = strtoupper(bin2hex($bytes));

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }
}
