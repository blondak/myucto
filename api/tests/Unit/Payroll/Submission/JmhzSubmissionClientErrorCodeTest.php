<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use PHPUnit\Framework\TestCase;

/**
 * Zmrazení hlášení i příprava z měsíčního přehledu musí obrazovce předat oba
 * vlastní kódy chyby 409. Každý přibyl v jiné větvi a jeden z nich zůstal
 * v nedosažitelném catch bloku — obrazovka pak dostala jen „conflict“ a
 * nenabídla odkaz na historii převzatých podání.
 */
final class JmhzSubmissionClientErrorCodeTest extends TestCase
{
    public function testLateDiscountConfirmationKeepsItsCode(): void
    {
        self::assertSame(
            JmhzSubmissionBridgeService::LATE_DISCOUNT_CONFIRMATION_CODE,
            JmhzSubmissionBridgeService::clientErrorCode(
                new JmhzXmlException(JmhzSubmissionBridgeService::LATE_DISCOUNT_CONFIRMATION_CODE, 'x'),
            ),
        );
    }

    public function testMonthSubmittedByPreviousProgramKeepsItsCode(): void
    {
        self::assertSame(
            'jmhz_period_submitted_externally',
            JmhzSubmissionBridgeService::clientErrorCode(
                new JmhzXmlException(JmhzSubmissionBridgeService::EXTERNAL_SUBMISSION_CODE, 'x'),
            ),
        );
    }

    public function testOtherValidationCodesAreGenericConflict(): void
    {
        self::assertSame(
            'conflict',
            JmhzSubmissionBridgeService::clientErrorCode(new JmhzXmlException('jmhz_xsd_invalid', 'x')),
        );
    }

    public function testBothSubmissionActionsUseTheSharedMapping(): void
    {
        $root = dirname(__DIR__, 4) . '/src/Action/Payroll/';
        foreach (['PayrollJmhzSubmissionFreezeAction.php', 'PayrollMonthlyChecklistPrepareAction.php'] as $file) {
            $source = (string) file_get_contents($root . $file);
            self::assertStringContainsString('JmhzSubmissionBridgeService::clientErrorCode($exception)', $source, $file);
            self::assertStringNotContainsString("'jmhz_period_submitted_externally'", $source, $file);
        }
    }
}
