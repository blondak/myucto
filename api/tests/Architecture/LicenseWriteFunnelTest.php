<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * `LicenseService::loadRow()` si licenční řádek pamatuje po dobu requestu (dřív se
 * četl dvakrát na každý přihlášený request). Memo je korektní jen dokud VŠECHNY
 * zápisy tečou přes `writeLicense()`, které ho zahazuje.
 *
 * Kdyby někdo přidal `UPDATE license ...` napřímo, služba by ve zbytku requestu
 * počítala stav ze zastaralého řádku — a to u licence nejsou kosmetické následky
 * (počet míst, platnost, degradovaný stav). Chyba by se navíc neprojevila hned,
 * takže tenhle test je tu místo spolehnutí se na komentář.
 */
final class LicenseWriteFunnelTest extends TestCase
{
    public function testEveryLicenseRowWriteGoesThroughTheFunnel(): void
    {
        $path = dirname(__DIR__, 2) . '/src/Service/License/LicenseService.php';
        $source = (string) file_get_contents($path);
        self::assertNotSame('', $source);

        $lines = explode("\n", $source);
        $offenders = [];

        foreach ($lines as $i => $line) {
            if (preg_match('/\b(UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+license\b/i', $line) !== 1) {
                continue;
            }

            // Zápis musí být argumentem writeLicense(). Volání bývá víceřádkové
            // (SQL na dalším řádku), proto se díváme i na pár řádků nad.
            $context = implode("\n", array_slice($lines, max(0, $i - 4), 6));
            if (str_contains($context, 'writeLicense(')) {
                continue;
            }

            // Mutex v renewIfDue() je vědomá výjimka: je to atomický UPDATE, který
            // rozhoduje o právu obnovit token, a memo si po něm ruší sám.
            if (str_contains($context, 'SET last_check_at = NOW()')) {
                continue;
            }

            $offenders[] = sprintf('řádek %d: %s', $i + 1, trim($line));
        }

        self::assertSame(
            [],
            $offenders,
            "Zápis do tabulky `license` mimo writeLicense() — memo v loadRow() by zůstalo zastaralé:\n  "
            . implode("\n  ", $offenders),
        );
    }

    public function testMutexInvalidatesTheMemoItself(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Service/License/LicenseService.php'
        );

        $start = strpos($source, 'public function renewIfDue()');
        self::assertNotFalse($start);
        $end = strpos($source, 'public function', $start + 10);
        $body = substr($source, $start, ($end === false ? strlen($source) : $end) - $start);

        self::assertStringContainsString(
            '$this->rowCache = null;',
            $body,
            'Mutex přepisuje last_check_at, takže po něm musí memo padnout.',
        );
    }
}
