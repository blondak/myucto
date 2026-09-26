<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollEmploymentJmhzActivityFamilyTest extends TestCase
{
    /**
     * Společník, jednatel a člen orgánu mohou mít druh činnosti K nebo N až S
     * (kontrola 343: formulář `cinnostKS`), ne jen S. Prokurista je P.
     *
     * @return iterable<string,array{string,string,bool}>
     */
    public static function corporateBodyCodes(): iterable
    {
        foreach (['K', 'N', 'O', 'P', 'Q', 'R', 'S'] as $code) {
            yield "statutory {$code}" => ['statutory_body', $code, true];
            yield "partner {$code}" => ['partner_dependent', $code, true];
        }
        yield 'foster carer is not a corporate body' => ['statutory_body', 'M', false];
        yield 'employment code is not a corporate body' => ['statutory_body', '1', false];
        yield 'agreement code is not a corporate body' => ['statutory_body', 'T', false];
    }

    #[DataProvider('corporateBodyCodes')]
    public function testCorporateBodyRelationAcceptsCinnostKsActivities(
        string $relationType,
        string $activityCode,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            PayrollEmploymentJmhzActivityFamily::matches($relationType, $activityCode, '1'),
        );
    }

    public function testCorporateBodyActivityStillRequiresNoRelationshipDetail(): void
    {
        self::assertFalse(PayrollEmploymentJmhzActivityFamily::matches('statutory_body', 'P', null));
        self::assertFalse(PayrollEmploymentJmhzActivityFamily::matches('employment', 'P', '1'));
    }
}
