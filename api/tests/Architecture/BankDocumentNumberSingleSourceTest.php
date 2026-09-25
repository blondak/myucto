<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Číslo dokladu bankovního zápisu má jediný zdroj:
 * {@see \MyInvoice\Service\Accounting\Bank\BankDocumentNumber}, dosazený v
 * {@see \MyInvoice\Service\Accounting\PostingService::postDocument()}.
 *
 * Dřív si ho každá cesta skládala sama (párování, pravidla, převody, kreditky) a dvě
 * kopie téhož helperu stály v BankPostingService a TransferPairService. Číslo pak
 * záviselo na tom, přes který výpis pohyb přišel, a po novém importu výpisu se měnilo.
 * Tenhle test hlídá, aby se žádná cesta k vlastnímu číslování nevrátila.
 */
#[Group('architecture')]
final class BankDocumentNumberSingleSourceTest extends TestCase
{
    public function testPostingServiceAssignsBankDocumentNumber(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/Accounting/PostingService.php');

        self::assertMatchesRegularExpression(
            "/\\\$bankDocument = BankDocumentNumber::numbersSource\(\\\$sourceType\) && \\\$sourceId !== null;\s*"
                . "\\\$documentNo = \\\$bankDocument\s*\?\s*\\\$this->bankDocumentNumber\(\)->forTransaction\(/",
            $code,
            'PostingService::postDocument() musí číslo bankovního dokladu brát z BankDocumentNumber.',
        );
        self::assertContains('card_settlement', \MyInvoice\Service\Accounting\Bank\BankDocumentNumber::SOURCE_TYPES,
            'Vypořádání platby kartou se opírá o týž bankovní výpis a nese číslo jeho pohybu.');
    }

    public function testNoBankPostingPassesItsOwnDocumentNumber(): void
    {
        $offenders = [];
        $bankCalls = 0;
        foreach ($this->sourceFiles() as $path => $code) {
            foreach ($this->postDocumentCalls($code) as $call) {
                if (!preg_match("/^\s*[^,]+,\s*'bank'\s*,/", $call)) {
                    continue;
                }
                $bankCalls++;
                if (str_contains($call, "'document_no'")) {
                    $offenders[] = $path;
                }
            }
        }

        self::assertGreaterThanOrEqual(7, $bankCalls, 'Guard nenašel bankovní volání postDocument() — hledá špatně.');
        self::assertSame([], $offenders, sprintf(
            "Bankovní zápis s vlastním číslem dokladu:\n  %s\n\n"
                . 'Číslo dosadí PostingService z BankDocumentNumber, volající ho neposílá.',
            implode("\n  ", array_unique($offenders)),
        ));
    }

    public function testNoOtherBankDocumentNumberHelper(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $path => $code) {
            if (str_ends_with($path, 'Service/Accounting/Bank/BankDocumentNumber.php')) {
                continue;
            }
            if (preg_match("/function\s+documentNo\s*\(\s*array\s+\\\$tx/", $code) === 1) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, 'Vlastní skládání čísla bankovního dokladu mimo BankDocumentNumber.');
    }

    /** @return iterable<string,string> relativní cesta → obsah */
    private function sourceFiles(): iterable
    {
        $root = dirname(__DIR__, 2) . '/src';
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                yield $path => (string) file_get_contents($file->getPathname());
            }
        }
    }

    /**
     * Argumenty každého volání `->postDocument(` (text mezi závorkami, vyvážené závorky).
     *
     * @return list<string>
     */
    private function postDocumentCalls(string $code): array
    {
        $calls = [];
        $offset = 0;
        while (($pos = strpos($code, '->postDocument(', $offset)) !== false) {
            $start = $pos + strlen('->postDocument(');
            $depth = 1;
            $i = $start;
            $len = strlen($code);
            while ($i < $len && $depth > 0) {
                $ch = $code[$i];
                if ($ch === '(' || $ch === '[') {
                    $depth++;
                } elseif ($ch === ')' || $ch === ']') {
                    $depth--;
                }
                $i++;
            }
            $calls[] = substr($code, $start, $i - $start - 1);
            $offset = $i;
        }
        return $calls;
    }
}
