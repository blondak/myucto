<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunOverrideReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Minimum, které musí splnit odůvodnění výjimky u mzdové validace.
 *
 * Schválení bez odůvodnění je horší než žádné — vypadá jako doložené
 * rozhodnutí, aniž by čímkoli bylo. Test drží hranici, aby ji nikdo omylem
 * nesnížil na „prázdné taky projde".
 */
final class PayrollRunOverrideReasonTest extends TestCase
{
    public function testRealSentencePasses(): void
    {
        self::assertSame(
            'Zaměstnanec byl celý měsíc na neplaceném volnu.',
            PayrollRunOverrideReason::normalize(
                '  Zaměstnanec byl celý měsíc na neplaceném volnu.  ',
            ),
        );
    }

    /** Bílé znaky se sjednotí, jinak by „ok" s deseti odřádkováními prošlo. */
    public function testWhitespaceIsCollapsedBeforeMeasuring(): void
    {
        self::assertSame(
            'Nález je doložen písemným souhlasem zaměstnance.',
            PayrollRunOverrideReason::normalize(
                "Nález\tje  doložen\n\npísemným   souhlasem\r\nzaměstnance.",
            ),
        );

        $this->expectException(\InvalidArgumentException::class);
        PayrollRunOverrideReason::normalize("ok\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n");
    }

    /** @return list<array{mixed,string}> */
    public static function hollowReasons(): array
    {
        return [
            'prázdné' => [' ', 'povinný'],
            'null' => [null, 'povinný'],
            'číslo místo textu' => [42, 'povinný'],
            'jedno slovo' => ['ok', '20 znaků'],
            'jedno delší slovo' => ['schvalujitutovyjimkuprotoze', 'nejméně 3 slovech'],
            'dvě slova' => ['výjimka odsouhlasena', 'nejméně 3 slovech'],
            'výplň písmen' => ['aaaa aaaa aaaaaaaaaaaaaa', 'čitelná věta'],
            'výplň interpunkce' => ['... ... ...........................', 'čitelná věta'],
            'výplň číslic' => ['1111 2222 3333333333333', 'čitelná věta'],
            'přes strop sloupce' => [str_repeat('a', 520) . ' b c', '500 znaků'],
        ];
    }

    #[DataProvider('hollowReasons')]
    public function testHollowReasonIsRefused(mixed $reason, string $expected): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/u');
        PayrollRunOverrideReason::normalize($reason);
    }

    /** Přesně na hranici — dvacet znaků a nejméně tři slova ještě projde. */
    public function testExactMinimumPasses(): void
    {
        $reason = 'chybi doklad ke mzde';
        self::assertSame(PayrollRunOverrideReason::MIN_LENGTH, mb_strlen($reason));
        self::assertSame($reason, PayrollRunOverrideReason::normalize($reason));
    }

    /** Odvolání výjimky odůvodnění mít nemusí; když ho má, platí táž pravidla. */
    public function testOptionalReasonAcceptsNothingButNotGarbage(): void
    {
        self::assertNull(PayrollRunOverrideReason::normalizeOptional(null));
        self::assertNull(PayrollRunOverrideReason::normalizeOptional('   '));
        self::assertSame(
            'Doklad byl nakonec doložen, výjimka není potřeba.',
            PayrollRunOverrideReason::normalizeOptional(
                'Doklad byl nakonec doložen, výjimka není potřeba.',
            ),
        );

        $this->expectException(\InvalidArgumentException::class);
        PayrollRunOverrideReason::normalizeOptional('ok');
    }
}
