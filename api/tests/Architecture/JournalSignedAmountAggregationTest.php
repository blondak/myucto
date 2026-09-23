<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class JournalSignedAmountAggregationTest extends TestCase
{
    public function testJournalSqlAggregationsDoNotUseRawAmount(): void
    {
        $root = dirname(__DIR__, 3) . '/api/src';
        $violations = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (!str_contains($source, 'journal_entry_lines')) {
                continue;
            }
            foreach (token_get_all($source) as $token) {
                if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $literal = $token[1];
                if (preg_match('/SUM\s*\([^)]*\b(?:l|jl|jel|line)\.amount\b/is', $literal) === 1
                    || (str_contains($literal, 'journal_entry_lines')
                        && preg_match('/CASE\s+WHEN\s+side\s*=.*?THEN\s+amount\b/is', $literal) === 1)
                ) {
                    $violations[] = $file->getPathname() . ':' . $token[2];
                }
            }
        }

        self::assertSame([], $violations, "Agregace deníku musí používat signed_amount:\n" . implode("\n", $violations));
    }
}
