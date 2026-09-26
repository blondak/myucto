<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
            foreach (self::rawAggregationLines($source) as $line) {
                $violations[] = $file->getPathname() . ':' . $line;
            }
        }

        self::assertSame([], $violations, "Agregace deníku musí používat signed_amount:\n" . implode("\n", $violations));
    }

    #[DataProvider('sqlSamples')]
    public function testGuardRecognizesSqlStringForms(string $source, bool $unsafe): void
    {
        self::assertSame($unsafe, self::rawAggregationLines($source) !== []);
    }

    /** @return iterable<string,array{string,bool}> */
    public static function sqlSamples(): iterable
    {
        yield 'quoted sum' => ['<?php $sql = "SELECT SUM(dl.amount) FROM journal_entry_lines dl";', true];
        yield 'interpolated sum' => ['<?php $sql = "SELECT SUM(CASE WHEN l.side = \'debit\' THEN l.amount ELSE -l.amount END) FROM {$linesSql}"; $linesSql = "journal_entry_lines l";', true];
        yield 'reconciler expression' => ['<?php $sql = "journal_entry_lines l"; $sign = "CASE WHEN l.side = \'credit\' THEN l.amount ELSE -l.amount END";', true];
        yield 'signed sum' => ['<?php $sql = "SELECT SUM(dl.signed_amount) FROM journal_entry_lines dl";', false];
        yield 'interpolated settlement side' => ['<?php $sql = "SELECT SUM(CASE WHEN l.side = \'{$settleSide}\' THEN l.amount ELSE -l.amount END) FROM journal_entry_lines l";', true];
        yield 'heredoc settlement side' => ["<?php \$sql = <<<SQL\nSELECT SUM(CASE WHEN l.side = '{\$settleSide}' THEN l.amount ELSE -l.amount END) FROM journal_entry_lines l\nSQL;", true];
        yield 'comment only' => ['<?php // SELECT SUM(l.amount) FROM journal_entry_lines l', false];
    }

    /** @return list<int> */
    private static function rawAggregationLines(string $source): array
    {
        $lines = [];
        $literals = [];
        $quoted = null;
        $lineNo = 1;
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                if ($token === '"') {
                    if ($quoted === null) {
                        $quoted = '';
                    } else {
                        $literals[] = [$quoted, $lineNo];
                        $quoted = null;
                    }
                } elseif ($quoted !== null) {
                    $quoted .= $token;
                }
                continue;
            }
            if ($token[0] === T_START_HEREDOC) {
                $quoted = '';
                $lineNo = $token[2];
            } elseif ($token[0] === T_END_HEREDOC) {
                $literals[] = [$quoted ?? '', $lineNo];
                $quoted = null;
            } elseif ($quoted !== null) {
                if ($quoted === '') {
                    $lineNo = $token[2];
                }
                $quoted .= $token[1];
            } elseif ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = [$token[1], $token[2]];
            }
        }
        foreach ($literals as [$literal, $lineNo]) {
            if (preg_match('/SUM\s*\([^)]*\b(?:l|jl|jel|line|dl)\.amount\b/is', $literal) === 1
                || preg_match('/CASE\s+WHEN\s+(?:l|jl|jel|line|dl)\.side\s*=\s*[\'\"](?:debit|credit)[\'\"]\s+THEN\s+-?(?:l|jl|jel|line|dl)\.amount\b/is', $literal) === 1
                || (str_contains($literal, 'journal_entry_lines')
                    && preg_match('/CASE\s+WHEN\s+side\s*=.*?THEN\s+amount\b/is', $literal) === 1)
            ) {
                $lines[] = $lineNo;
            }
        }
        return $lines;
    }
}
