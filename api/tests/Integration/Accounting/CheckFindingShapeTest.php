<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\CheckFindingNormalizer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tvar nálezů kontrol — to, co uživatel v detailu opravdu uvidí.
 *
 * Vzniklo z nálezů nad ostrými daty, které se v UI projevily takhle:
 *   · kontrola hlásila „1 nález" a popup byl PRÁZDNÝ (vracela jen `count`, žádné řádky),
 *   · sloupec Datum zůstával prázdný u 21 z 21 nálezů (dotaz datum vůbec nevybíral),
 *   · v poznámce svítily syrové anglické kódy (`amount_mismatch`).
 *
 * Testy nekontrolují konkrétní kontrolu, ale KONTRAKT: cokoli, co se v UI zobrazí jako
 * seznam, musí mít řádky, a řádek dokladu musí nést datum. Kontrola, která tvrdí nález
 * a neukáže ho, je horší než žádná — uživatel ví, že něco je špatně, a nemá jak zjistit co.
 */
#[Group('integration')]
final class CheckFindingShapeTest extends TestCase
{
    private CheckFindingNormalizer $normalizer;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DI.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->normalizer = $c->get(CheckFindingNormalizer::class);
            $c->get(Connection::class)->close();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
    }

    /**
     * Nenulový počet musí znamenat nenulový seznam.
     *
     * Kontrola `depreciation_missing` vracela jen `['count' => 1]` — UI hlásilo „1 nález"
     * a otevřelo prázdnou tabulku.
     */
    public function testNonZeroCountAlwaysHasRows(): void
    {
        $check = [
            'key'   => 'depreciation_missing',
            'value' => ['count' => 1, 'items' => [
                ['doc_type' => 'asset', 'doc_id' => 7, 'doc_no' => 'INV-1', 'doc_date' => '2026-01-31'],
            ]],
        ];

        $out = $this->normalizer->normalize($check);

        self::assertSame(1, $out['value']['count']);
        self::assertCount(1, $out['value']['findings'], 'Počet > 0 bez řádků = prázdný popup.');
    }

    /** Řádek dokladu nese datum — bez něj nejde nález zařadit v čase. */
    public function testDocumentFindingCarriesDate(): void
    {
        $check = [
            'key'   => 'paid_purchases_open_saldo',
            'value' => ['count' => 1, 'items' => [
                ['id' => 258, 'doc_no' => '2026-0016', 'doc_date' => '2026-06-01',
                 'partner_name' => 'Dodavatel s.r.o.', 'saldo' => 123456.78],
            ]],
        ];

        $finding = $this->normalizer->normalize($check)['value']['findings'][0];

        self::assertSame('2026-06-01', $finding['doc_date']);
        self::assertSame('2026-0016', $finding['doc_no']);
        self::assertEqualsWithDelta(123456.78, $finding['amount'], 0.01);
    }

    /**
     * Kódy nálezů se posílají jako POLE, ne slepené do textu.
     *
     * Slepený text nešlo na klientovi přeložit, takže v české aplikaci svítilo
     * „amount_mismatch" — a nešlo k němu doplnit ani o kolik částka nesedí.
     */
    public function testIssueCodesAreSentAsArrayWithDetail(): void
    {
        $check = [
            'key'   => 'payment_match_audit',
            'value' => ['count' => 1, 'items' => [
                [
                    'doc_id' => 43, 'doc_no' => 'CZ635SZAEUI', 'tx_posted_at' => '2026-01-22',
                    'partner_name' => 'Amazon EU', 'impact_czk' => 3.67,
                    'issues' => ['amount_mismatch'],
                    'detail' => ['amount_mismatch' => ['diff' => -3.67, 'expected' => 236.84]],
                ],
            ]],
        ];

        $finding = $this->normalizer->normalize($check)['value']['findings'][0];

        self::assertSame(['amount_mismatch'], $finding['issues']);
        self::assertEqualsWithDelta(-3.67, $finding['detail']['amount_mismatch']['diff'], 0.01);
        self::assertNull($finding['note'], 'Kódy se do note neslepují — překládají se na klientovi.');
        self::assertSame('2026-01-22', $finding['doc_date']);
    }
}
