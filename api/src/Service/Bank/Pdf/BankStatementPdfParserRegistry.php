<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf;

use Smalot\PdfParser\Parser as PdfTextParser;

/**
 * Registry bank-specifických PDF parserů výpisů. Extrahuje text jednou (Smalot),
 * pak zkusí parsery v pořadí — první, který text rozpozná (`supports()`), parsuje.
 *
 * Analogie {@see \MyInvoice\Service\Logbook\Fuel\FuelStatementParserRegistry}.
 */
final class BankStatementPdfParserRegistry
{
    /** @var list<BankStatementPdfParserInterface> */
    private array $parsers;

    /** @param list<BankStatementPdfParserInterface> $parsers */
    public function __construct(array $parsers)
    {
        $this->parsers = array_values($parsers);
    }

    /**
     * @return array{header:array,transactions:list<array<string,mixed>>,parser:string}
     * @throws \RuntimeException Když PDF nejde přečíst, nebo ho nerozpozná žádný parser.
     */
    public function parse(string $pdfBytes): array
    {
        $text = $this->extractText($pdfBytes);
        $parser = $this->parserFor($text);
        if ($parser === null) {
            throw new \RuntimeException('Pro tento PDF výpis nebyl nalezen žádný podporovaný bankovní parser.');
        }
        return $parser->parse($pdfBytes, $text) + ['parser' => $parser->key()];
    }

    /**
     * Textová vrstva PDF. Oddělené od {@see parse()}, aby šlo NEJDŘÍV zjistit, jestli
     * je příloha z e-mailu vůbec bankovní výpis, a teprve pak ji parsovat — jinak by
     * se „nerozpoznaná příloha" nedala odlišit od „výpis rozpoznán, ale parsování
     * selhalo" a ztráta výpisu by prošla v tichu.
     *
     * @throws \RuntimeException Když PDF nejde přečíst nebo nemá textovou vrstvu.
     */
    public function extractText(string $pdfBytes): string
    {
        if (!str_starts_with($pdfBytes, '%PDF')) {
            throw new \RuntimeException('Soubor není platné PDF.');
        }
        try {
            $doc = (new PdfTextParser())->parseContent($pdfBytes);
            $text = implode("\n", array_map(static fn ($p) => $p->getText(), $doc->getPages()));
        } catch (\Throwable $e) {
            throw new \RuntimeException('Nelze přečíst text z PDF: ' . $e->getMessage(), 0, $e);
        }
        if (trim($text) === '') {
            throw new \RuntimeException('PDF neobsahuje žádný extrahovatelný text (naskenovaný obrázek?).');
        }
        return $text;
    }

    /** Parser, který text rozpozná jako výpis své banky (pořadí = priorita). */
    public function parserFor(string $text): ?BankStatementPdfParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($text)) return $parser;
        }
        return null;
    }
}
