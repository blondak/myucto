<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Pdf;

use MyInvoice\Service\Pdf\JournalPdfRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Regresní test na produkční pád: export účetního deníku velké firmy (reálný
 * případ ~4,8 tis. zápisů / ~16,7 tis. řádků za jeden rok, dohromady přes
 * 20 tis. řádků tabulky) padal s "HTML code size is larger than
 * pcre.backtrack_limit" — {@see JournalPdfRenderer::render()} teď píše přes
 * {@see \MyInvoice\Service\Pdf\ChunkedHtmlWriter} po dávkách.
 *
 * Reálná velikost (~20 tis. řádků) reálně renderovaná přes mPDF by test běžně
 * zpomalila na desítky sekund (layout stránek, ne jen string zpracování) —
 * proto tu je jen menší synteticky reprezentativní vzorek přes ostrý renderer
 * (end-to-end přes journal.twig), a plný objem/pcre limit je pokrytý rychlým
 * {@see ChunkedHtmlWriterTest} se sníženým pcre.backtrack_limit.
 */
final class JournalPdfRendererLargeExportTest extends TestCase
{
    public function testRendersManyEntriesWithMultipleLinesWithoutError(): void
    {
        $data = self::syntheticJournalData(800, 3);

        $pdf = (new JournalPdfRenderer())->render($data);

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertGreaterThan(2000, strlen($pdf));
    }

    /**
     * @return array<string,mixed>
     */
    private static function syntheticJournalData(int $entryCount, int $linesPerEntry): array
    {
        $entries = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        for ($i = 1; $i <= $entryCount; $i++) {
            $amount = round($i * 12.34, 2);
            $lines = [];
            for ($l = 0; $l < $linesPerEntry; $l++) {
                $side = $l === 0 ? 'debit' : 'credit';
                $lineAmount = $l === 0 ? $amount : round($amount / max(1, $linesPerEntry - 1), 2);
                $lines[] = [
                    'account_code' => (string) (200 + ($l * 11) + ($i % 50)),
                    'account_name' => 'Syntetický analytický účet č. ' . $l . ' pro zápis ' . $i,
                    'side' => $side,
                    'amount' => $lineAmount,
                ];
                if ($side === 'debit') {
                    $totalDebit += $lineAmount;
                } else {
                    $totalCredit += $lineAmount;
                }
            }

            $entries[] = [
                'entry_date' => '2024-01-01',
                'document_no' => 'DOC-' . $i,
                'description' => 'Syntetický testovací zápis číslo ' . $i . ' s delším popisem kvůli reálnému objemu HTML',
                'reversed_by' => null,
                'posted_at' => '2024-01-01 10:00:00',
                'automation_origin' => 'ručně',
                'amount' => $amount,
                'amount_side' => null,
                'lines' => $lines,
            ];
        }

        return [
            'entity' => [
                'name' => 'Testovací firma s.r.o.',
                'ico' => '12345678',
                'address' => 'Testovací 1, 100 00 Praha',
            ],
            'filters' => [
                'date_from' => '2024-01-01',
                'date_to' => '2024-12-31',
                'source_type' => null,
            ],
            'entries' => $entries,
            'totals' => [
                'debit' => round($totalDebit, 2),
                'credit' => round($totalCredit, 2),
                'count' => $entryCount,
            ],
        ];
    }
}
