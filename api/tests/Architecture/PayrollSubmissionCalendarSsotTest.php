<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "Dnes" a čas vyplnění podání ČSSZ a zdravotním pojišťovnám se berou jedinou
 * cestou, `PayrollSubmissionCalendar`. Kalendář podání je český; UTC datum
 * mezi půlnocí a 1. až 2. hodinou ranní vrací den předchozí, takže podání
 * ponese datum z minulosti a lhůtní kontroly se rozejdou s tím, co vidí
 * účetní. Strojový čas bez zóny (`date('Y-m-d')`, `new DateTimeImmutable('today')`)
 * závisí na nastavení serveru, tedy na tom, jestli proběhl Bootstrap.
 *
 * Hlídá se zdroj bez komentářů. Povolené zůstává UTC pro technické časové
 * značky v databázi (`Y-m-d H:i:s`), tady se zakazují jen tvary pro datum
 * a čas vyplnění.
 */
final class PayrollSubmissionCalendarSsotTest extends TestCase
{
    private const FORBIDDEN = [
        'UTC čas vyplnění (použij PayrollSubmissionCalendar::filledAt)'
            => '~setTimezone\(\s*new\s+\\\\?DateTimeZone\(\s*\'UTC\'\s*\)\s*\)\s*->format\(\s*\'Y-m-d\\\\TH:i:s\\\\Z\'\s*\)~',
        'gmdate s datem nebo časem vyplnění'
            => '~\bgmdate\(\s*\'Y-m-d(\\\\TH:i:s\\\\Z)?\'\s*\)~',
        'date() bez zóny (použij PayrollSubmissionCalendar::today)'
            => '~(?<![\w>:])date\(\s*\'Y-m-d\'\s*\)~',
        'DateTimeImmutable today/now bez zóny'
            => '~new\s+\\\\?DateTimeImmutable\(\s*\'(today|now)\'\s*\)~',
    ];

    public function testSubmissionCodeTakesTodayOnlyFromTheCalendar(): void
    {
        $root = dirname(__DIR__, 2) . '/src/Service/Payroll/Submission';
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (self::violations((string) file_get_contents($file->getPathname())) as $label) {
                $offenders[] = substr($file->getPathname(), strlen($root) + 1) . ': ' . $label;
            }
        }

        self::assertSame([], $offenders);
    }

    #[DataProvider('badSnippets')]
    public function testGuardRecognizesTheForbiddenShapes(string $code): void
    {
        self::assertNotSame([], self::violations('<?php ' . $code));
    }

    #[DataProvider('goodSnippets')]
    public function testGuardLeavesLegitimateTimeCodeAlone(string $code): void
    {
        self::assertSame([], self::violations('<?php ' . $code));
    }

    /** @return iterable<string,array{string}> */
    public static function badSnippets(): iterable
    {
        yield 'UTC Z' => ["\$a = \$now->setTimezone(new \\DateTimeZone('UTC'))\n    ->format('Y-m-d\\TH:i:s\\Z');"];
        yield 'gmdate dateTime' => ["\$a = gmdate('Y-m-d\\TH:i:s\\Z');"];
        yield 'gmdate date' => ["\$a = gmdate('Y-m-d');"];
        yield 'date' => ["\$a = date('Y-m-d');"];
        yield 'today' => ["\$a = new \\DateTimeImmutable('today');"];
        yield 'now' => ["\$a = new DateTimeImmutable('now');"];
    }

    /** @return iterable<string,array{string}> */
    public static function goodSnippets(): iterable
    {
        yield 'komentář' => ["// gmdate('Y-m-d') a date('Y-m-d') jsou tu jen v poznámce\n\$a = 1;"];
        yield 'DB časová značka' => ["\$a = gmdate('Y-m-d H:i:s');"];
        yield 'UTC audit mikrosekundy' => ["\$a = \$n->setTimezone(new \\DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');"];
        yield 'zóna zadána' => ["\$a = new \\DateTimeImmutable('now', new \\DateTimeZone('UTC'));"];
        yield 'metoda date' => ["\$a = \$this->date('Y-m-d');"];
    }

    /** @return list<string> */
    private static function violations(string $source): array
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }
        $found = [];
        foreach (self::FORBIDDEN as $label => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $found[] = $label;
            }
        }

        return $found;
    }
}
