<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinkPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupWorkReportLinkPolicyTest extends TestCase
{
    public function testPreservesOriginalTokenWithoutCollision(): void
    {
        $token = '00000000' . str_repeat('ab', 20);
        self::assertSame($token, CompanyBackupWorkReportLinkPolicy::restoreToken($token, false));
    }

    public function testExplicitRegenerationCreatesNewTokenInRuntimeFormat(): void
    {
        $token = str_repeat('a', 48);
        $replacement = CompanyBackupWorkReportLinkPolicy::restoreToken($token, true, 'regenerate');
        self::assertMatchesRegularExpression('/\A[0-9a-f]{48}\z/D', $replacement);
        self::assertNotSame($token, $replacement);
        self::assertSame(str_repeat('a', 48), $token);
    }

    #[DataProvider('rejectedCases')]
    public function testRejectsInvalidDecisionWithoutExposingToken(
        mixed $token,
        bool $collision,
        ?string $decision,
        string $code,
    ): void {
        try {
            CompanyBackupWorkReportLinkPolicy::restoreToken($token, $collision, $decision);
            self::fail('Neplatný kontext odkazu nesmí projít.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame('table:work_report_links', $e->registryKey);
            self::assertSame('token', $e->column);
            self::assertSame($code . ': table:work_report_links.token', $e->getMessage());
        }
    }

    /** @return iterable<string,array{mixed,bool,?string,string}> */
    public static function rejectedCases(): iterable
    {
        $token = str_repeat('a', 48);
        yield 'missing choice' => [$token, true, null, 'work_report_link_decision_required'];
        yield 'reject restore' => [$token, true, 'reject', 'work_report_link_collision_rejected'];
        yield 'stale regenerate' => [$token, false, 'regenerate', 'work_report_link_decision_out_of_scope'];
        yield 'stale reject' => [$token, false, 'reject', 'work_report_link_decision_out_of_scope'];
        yield 'unknown choice' => [$token, true, 'invalidate', 'work_report_link_decision_invalid'];
        yield 'unknown without collision' => [$token, false, '', 'work_report_link_decision_invalid'];
        foreach ([null, false, 42, '', str_repeat('a', 47), str_repeat('a', 49),
            str_repeat('A', 48), str_repeat('g', 48), $token . "\n"] as $index => $invalid) {
            yield 'invalid token ' . $index => [$invalid, true, 'regenerate', 'work_report_link_token_invalid'];
        }
    }
}
