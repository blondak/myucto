<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupLosslessJson;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceRemapDirective;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupLosslessJsonTest extends TestCase
{
    public function testNoopPreservesEveryByteAndVisitsDecodedPaths(): void
    {
        $json = " { \"0\" : [ 1.00 , 1e+09 , 922337203685477580812345, -0, \"ě\\u0041\", true, null, {} ], "
            . "\"nested\" : { \"\\u0069d\" : 7 } } \n";
        $visited = [];
        $actual = CompanyBackupLosslessJson::rewriteIntegerTokens(
            $json,
            static function (array $path, string $token) use (&$visited): ?int {
                $visited[] = [$path, $token];
                return null;
            },
        );
        self::assertSame($json, $actual);
        self::assertSame([
            [['0', 0], '1.00'], [['0', 1], '1e+09'],
            [['0', 2], '922337203685477580812345'], [['0', 3], '-0'],
            [['0', 4], '"ě\\u0041"'], [['0', 5], 'true'], [['0', 6], 'null'],
            [['nested', 'id'], '7'],
        ], $visited);
    }

    public function testReplacesOnlySelectedPositiveIntegerTokensWithoutReencoding(): void
    {
        $json = "{ \"rows\": [ {\"id\":12,\"value\":1.2300,\"raw\":1e+05}, "
            . "{\"id\":13,\"value\":-0.0}], \"note\":\"č\\u0061s\",\"other\":1 }";
        $actual = CompanyBackupLosslessJson::rewriteIntegerTokens(
            $json,
            static fn (array $path, string $token): ?int =>
                count($path) === 3 && $path[0] === 'rows' && $path[2] === 'id'
                    ? ((int) $token) + 100 : null,
        );
        self::assertSame(str_replace(['"id":12', '"id":13'], ['"id":112', '"id":113'], $json), $actual);
    }

    public function testDefersOnlyPositiveIntegerTokensAsTemporaryNull(): void
    {
        $json = '{ "reference_submission_id" : 27, "amount":1.200, "other":34 }';
        $deferred = CompanyBackupLosslessJson::rewriteIntegerTokens(
            $json,
            static fn (array $path, string $token): ?CompanyBackupReferenceRemapDirective =>
                $path === ['reference_submission_id']
                    ? CompanyBackupReferenceRemapDirective::Defer : null,
        );
        self::assertSame('{ "reference_submission_id" : null, "amount":1.200, "other":34 }', $deferred);
        self::assertSame($deferred, CompanyBackupLosslessJson::rewriteIntegerTokens(
            $deferred,
            static fn (): null => null,
        ));

        foreach (['{"id":"27"}', '{"id":27.0}', '{"id":0}', '{"id":-27}', '{"id":true}'] as $source) {
            try {
                CompanyBackupLosslessJson::rewriteIntegerTokens($source,
                    static fn (): CompanyBackupReferenceRemapDirective => CompanyBackupReferenceRemapDirective::Defer);
                self::fail('Defer smí nahradit jen kladný integer token.');
            } catch (CompanyBackupPreflightException $e) {
                self::assertSame('lossless_json_replacement_invalid', $e->errorCode);
            }
        }
    }

    /** @return iterable<string,array{string}> */
    public static function malformed(): iterable
    {
        foreach ([
            '', ' ', '{', '[1,]', '{"a":1,}', '{"a" 1}', '{"a":1 "b":2}',
            '{"a":01}', '{"a":+1}', '{"a":.1}', '{"a":1.}', '{"a":1e}',
            '{"a":1e+}', '{"a":--1}', '{"a":truefalse}', '{"a":NaN}',
            '{"a":Infinity}', '{"a":"\\x"}', '{"a":"\\u12G4"}',
            '{"a":"\\uD800"}', "{\"a\":\"\x01\"}", "{\"a\":\"\xFF\"}",
            '{"a":1}{"b":2}', '{"a":1} trailing', '{"a":1,"a":2}',
            '{"a":1,"\\u0061":2}', '{"0":1,"\\u0030":2}',
        ] as $index => $json) {
            yield 'malformed ' . $index => [$json];
        }
    }

    #[DataProvider('malformed')]
    public function testRejectsMalformedOrAmbiguousJson(string $json): void
    {
        try {
            CompanyBackupLosslessJson::rewriteIntegerTokens($json, static fn (): null => null);
            self::fail('Neplatný nebo nejednoznačný JSON nesmí projít.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('lossless_json_invalid', $e->errorCode);
            self::assertSame('lossless_json_invalid', $e->getMessage());
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalidReplacements(): iterable
    {
        foreach ([
            ['{"id":"12"}', '"12"'], ['{"id":12.0}', '12.0'],
            ['{"id":1e2}', '1e2'], ['{"id":true}', 'true'],
            ['{"id":false}', 'false'], ['{"id":null}', 'null'],
            ['{"id":0}', '0'], ['{"id":-12}', '-12'],
            ['{"id":922337203685477580812345}', '922337203685477580812345'],
        ] as [$json, $token]) {
            yield $token => [$json, $token];
        }
    }

    #[DataProvider('invalidReplacements')]
    public function testRejectsReplacementOfNonPositiveOrNonIntegerSource(string $json, string $token): void
    {
        try {
            CompanyBackupLosslessJson::rewriteIntegerTokens($json, static fn (): int => 45);
            self::fail('Náhrada tohoto tokenu nesmí projít: ' . $token);
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('lossless_json_replacement_invalid', $e->errorCode);
        }
    }

    public function testRejectsInvalidReplacementValueAndLimits(): void
    {
        foreach ([0, -1, '3', 2.5, true] as $replacement) {
            try {
                CompanyBackupLosslessJson::rewriteIntegerTokens('{"id":1}',
                    static fn (): mixed => $replacement);
                self::fail('Náhradní hodnota musí být kladné PHP int.');
            } catch (CompanyBackupPreflightException $e) {
                self::assertSame('lossless_json_replacement_invalid', $e->errorCode);
            }
        }
        foreach ([0, 4] as $maxBytes) {
            try {
                CompanyBackupLosslessJson::rewriteIntegerTokens('{"id":1}',
                    static fn (): null => null, $maxBytes);
                self::fail('Limit musí zastavit nadměrný JSON.');
            } catch (CompanyBackupPreflightException $e) {
                self::assertSame('lossless_json_limit_exceeded', $e->errorCode);
            }
        }
        $deep = str_repeat('[', 65) . '0' . str_repeat(']', 65);
        try {
            CompanyBackupLosslessJson::rewriteIntegerTokens($deep, static fn (): null => null);
            self::fail('Nadměrná hloubka musí být odmítnuta.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('lossless_json_limit_exceeded', $e->errorCode);
        }
    }

    public function testRootScalarsAndNumericObjectKeysRemainDistinctFromArrayIndices(): void
    {
        $paths = [];
        self::assertSame('42', CompanyBackupLosslessJson::rewriteIntegerTokens(
            '7', static function (array $path, string $token) use (&$paths): ?int {
                $paths[] = $path;
                return $token === '7' ? 42 : null;
            },
        ));
        self::assertSame([[]], $paths);
        $paths = [];
        CompanyBackupLosslessJson::rewriteIntegerTokens('{"0":1,"list":[2]}',
            static function (array $path, string $token) use (&$paths): ?int {
                $paths[] = $path;
                return null;
            });
        self::assertSame([['0'], ['list', 0]], $paths);
    }

    public function testReplacesOnlyDecodedStringPathAndPreservesUnrelatedBytes(): void
    {
        $source = " { \"\\u006cogo_path\" : \"old\\/logo.png\", "
            . '"total":1.2300,"exponent":1e+09,"id":7,"nested":{"logo_path":"old/logo.png"} } ';
        $expected = str_replace('"old\\/logo.png"', '"new/logo.png"', $source);
        self::assertSame($expected, CompanyBackupLosslessJson::replaceStringAtPath(
            $source, ['logo_path'], 'old/logo.png', 'new/logo.png',
        ));
        self::assertSame($source, CompanyBackupLosslessJson::replaceStringAtPath(
            $source, ['logo_path'], 'old/logo.png', 'old/logo.png',
        ));
        self::assertSame('{"0":"new","items":["old"]}',
            CompanyBackupLosslessJson::replaceStringAtPath(
                '{"0":"old","items":["old"]}', ['0'], 'old', 'new',
            ));
        self::assertSame('{"0":"old","items":["new"]}',
            CompanyBackupLosslessJson::replaceStringAtPath(
                '{"0":"old","items":["old"]}', ['items', 0], 'old', 'new',
            ));
    }

    public function testStringReplacementRejectsMissingWrongTypeMismatchAndAmbiguity(): void
    {
        foreach ([
            ['{"other":"old"}', ['logo_path'], 'lossless_json_target_missing'],
            ['{"logo_path":null}', ['logo_path'], 'lossless_json_replacement_invalid'],
            ['{"logo_path":{"x":1}}', ['logo_path'], 'lossless_json_replacement_invalid'],
            ['{"logo_path":"other"}', ['logo_path'], 'lossless_json_replacement_invalid'],
            ['{"logo_path":"old","\\u006cogo_path":"old"}', ['logo_path'], 'lossless_json_invalid'],
            ['{"logo_path":"old","nested":{"a":1,"a":2}}', ['logo_path'], 'lossless_json_invalid'],
            ['{"logo_path":"old","x":1e+}', ['logo_path'], 'lossless_json_invalid'],
        ] as [$source, $path, $code]) {
            try {
                CompanyBackupLosslessJson::replaceStringAtPath(
                    $source, $path, 'old', 'new',
                );
                self::fail('Chybný JSON nebo cílová hodnota musí být odmítnuty.');
            } catch (CompanyBackupPreflightException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    public function testStringReplacementEnforcesUtf8DepthAndInputOutputByteLimits(): void
    {
        foreach ([
            ['{"logo_path":"old"}', "\xFF", 100, 'lossless_json_replacement_invalid'],
            ['{"logo_path":"old"}', 'longer', 20, 'lossless_json_limit_exceeded'],
            ['{"logo_path":"old"}', 'new', 5, 'lossless_json_limit_exceeded'],
            [str_repeat('[', 65) . '"old"' . str_repeat(']', 65), 'new', 500,
                'lossless_json_limit_exceeded'],
        ] as [$source, $replacement, $maxBytes, $code]) {
            try {
                CompanyBackupLosslessJson::replaceStringAtPath(
                    $source, $source[0] === '[' ? array_fill(0, 65, 0) : ['logo_path'],
                    'old', $replacement, $maxBytes,
                );
                self::fail('Neplatné UTF-8 nebo limit musí zastavit náhradu.');
            } catch (CompanyBackupPreflightException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    public function testIntegerMapperStillRejectsStringReplacement(): void
    {
        self::assertSame('123', CompanyBackupLosslessJson::rewriteIntegerTokens(
            '1', static fn (): int => 123, 1,
        ));
        try {
            CompanyBackupLosslessJson::rewriteIntegerTokens(
                '{"id":7}', static fn (): string => '8',
            );
            self::fail('Integer mapper nesmí začít přijímat string náhrady.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('lossless_json_replacement_invalid', $e->errorCode);
        }
    }
}
