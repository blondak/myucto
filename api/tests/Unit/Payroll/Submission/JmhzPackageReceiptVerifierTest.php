<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzPackageReceiptVerifier;
use MyInvoice\Service\Payroll\Submission\PayrollReceiptVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceipt;
use PHPUnit\Framework\TestCase;

/**
 * Stav rozděleného hlášení JMHZ ze stavů protokolů jeho dílčích balíků.
 */
final class JmhzPackageReceiptVerifierTest extends TestCase
{
    public function testAggregatesPackageStatuses(): void
    {
        self::assertSame('processing', JmhzPackageReceiptVerifier::aggregate([1 => 'accepted', 2 => null]));
        self::assertSame('accepted', JmhzPackageReceiptVerifier::aggregate([2 => 'accepted', 1 => 'accepted']));
        self::assertSame('rejected', JmhzPackageReceiptVerifier::aggregate([1 => 'rejected', 2 => null]));
        self::assertSame('partially_accepted', JmhzPackageReceiptVerifier::aggregate([1 => 'accepted', 2 => 'rejected']));
        self::assertSame('partially_accepted', JmhzPackageReceiptVerifier::aggregate([1 => 'partially_accepted', 2 => 'accepted']));
    }

    /**
     * Protokol druhého balíku po přijatém prvním: podání je přijaté a stav
     * součásti balíku se doplní.
     */
    public function testSecondPackageProtocolCompletesTheSubmission(): void
    {
        $inner = new class implements PayrollReceiptVerifierInterface {
            public function verify(string $bytes, string $channel, string $environment, ?string $expectedCorrelationReference): PayrollVerifiedReceipt
            {
                return new PayrollVerifiedReceipt('accepted', $expectedCorrelationReference, [], []);
            }
        };
        $verifier = new JmhzPackageReceiptVerifier(
            $inner,
            static fn (int $supplierId, string $environment, int $submissionId): array => [501 => 'accepted'],
            7,
            'test',
            90,
            502,
            [501 => 1, 502 => 2],
        );

        $verified = $verifier->verify('<protocol/>', 'vrep_apep', 'test', 'CORR-2');

        self::assertSame('accepted', $verified->remoteStatus);
        self::assertSame('CORR-2', $verified->correlationReference);
        self::assertSame([502 => 'accepted'], $verified->partStatuses);
    }
}
