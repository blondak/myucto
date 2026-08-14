<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionFlagMatrix;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionGuidPolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use PHPUnit\Framework\TestCase;

final class JmhzSubmissionGuidPolicyTest extends TestCase
{
    private JmhzSubmissionGuidPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new JmhzSubmissionGuidPolicy();
    }

    /**
     * Řádné podání dostává nový GUID pokaždé — i po zamítnutí. Zamítnuté podání
     * u ČSSZ nezaniklo jako záznam, ale jako platné hlášení, takže se jeho GUID
     * nesmí recyklovat.
     */
    public function testRegularSubmissionAlwaysGetsANewGuid(): void
    {
        foreach ([
            JmhzSubmissionGuidPolicy::SUBMISSION_NONE,
            JmhzSubmissionGuidPolicy::SUBMISSION_REJECTED,
            JmhzSubmissionGuidPolicy::SUBMISSION_CANCELLED,
        ] as $state) {
            self::assertSame(
                JmhzSubmissionGuidPolicy::NEW_GUID,
                $this->policy->forSubmission(JmhzSubmissionFlagMatrix::TYPE_REGULAR, $state),
            );
        }
    }

    public function testAmendmentReusesTheGuidOfTheRegularSubmission(): void
    {
        self::assertSame(
            JmhzSubmissionGuidPolicy::REGULAR_SUBMISSION_GUID,
            $this->policy->forSubmission(
                JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
                JmhzSubmissionGuidPolicy::SUBMISSION_ACCEPTED,
            ),
        );
    }

    /**
     * Zamítnuté opravné podání se posílá znovu — pořád se stejným GUID řádného
     * podání, protože se pořád opravuje totéž hlášení.
     */
    public function testRejectedAmendmentIsResentUnderTheSameRegularGuid(): void
    {
        self::assertSame(
            JmhzSubmissionGuidPolicy::REGULAR_SUBMISSION_GUID,
            $this->policy->forSubmission(
                JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
                JmhzSubmissionGuidPolicy::SUBMISSION_REJECTED,
            ),
        );
    }

    public function testAmendmentWithoutARegularSubmissionIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/nelze poslat dřív než řádné/');
        $this->policy->forSubmission(
            JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
            JmhzSubmissionGuidPolicy::SUBMISSION_NONE,
        );
    }

    /**
     * Stornem GUID řádného podání zaniká. Další opravné podání se tedy nemá
     * na co navázat a musí nejdřív vzniknout nové řádné.
     */
    public function testAmendmentAfterCancellationIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/Stornem zanikl GUID/');
        $this->policy->forSubmission(
            JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
            JmhzSubmissionGuidPolicy::SUBMISSION_CANCELLED,
        );
    }

    public function testFormInRegularSubmissionGetsANewGuid(): void
    {
        self::assertSame(
            JmhzSubmissionGuidPolicy::NEW_GUID,
            $this->policy->forForm(
                JmhzSubmissionFlagMatrix::TYPE_REGULAR,
                JmhzSubmissionFlagMatrix::TYPE_REGULAR,
                JmhzSubmissionGuidPolicy::FORM_NONE,
            ),
        );
    }

    /**
     * Nejzáludnější dvojice celé specifikace, proto obě strany v jednom testu:
     * zamítnutá součást se posílá ZNOVU jako řádná s NOVÝM GUID, kdežto oprava
     * bezvadné součásti používá PŮVODNÍ GUID s typem O.
     */
    public function testRejectedFormIsResentAsNewWhileValidFormIsAmendedInPlace(): void
    {
        self::assertSame(
            JmhzSubmissionGuidPolicy::NEW_GUID,
            $this->policy->forForm(
                JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
                JmhzSubmissionFlagMatrix::TYPE_REGULAR,
                JmhzSubmissionGuidPolicy::FORM_REJECTED,
            ),
        );
        self::assertSame(
            JmhzSubmissionGuidPolicy::ORIGINAL_FORM_GUID,
            $this->policy->forForm(
                JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
                JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
                JmhzSubmissionGuidPolicy::FORM_ACCEPTED,
            ),
        );
    }

    public function testCancelledFormIsResentWithANewGuid(): void
    {
        self::assertSame(
            JmhzSubmissionGuidPolicy::NEW_GUID,
            $this->policy->forForm(
                JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
                JmhzSubmissionFlagMatrix::TYPE_REGULAR,
                JmhzSubmissionGuidPolicy::FORM_CANCELLED,
            ),
        );
    }

    public function testCancellingAValidFormReferencesItsOriginalGuid(): void
    {
        self::assertSame(
            JmhzSubmissionGuidPolicy::ORIGINAL_FORM_GUID,
            $this->policy->forForm(
                JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
                JmhzSubmissionFlagMatrix::TYPE_CANCELLATION,
                JmhzSubmissionGuidPolicy::FORM_ACCEPTED,
            ),
        );
    }

    public function testResendingAValidFormAsRegularIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/opravuje se pod původním GUID/');
        $this->policy->forForm(
            JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
            JmhzSubmissionFlagMatrix::TYPE_REGULAR,
            JmhzSubmissionGuidPolicy::FORM_ACCEPTED,
        );
    }

    public function testAmendingARejectedFormIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/posílá se\s+znovu s novým GUID/');
        $this->policy->forForm(
            JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
            JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
            JmhzSubmissionGuidPolicy::FORM_REJECTED,
        );
    }

    public function testCancellationSubmissionCarriesNoForms(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/neobsahuje součásti/');
        $this->policy->forForm(
            JmhzSubmissionFlagMatrix::TYPE_CANCELLATION,
            JmhzSubmissionFlagMatrix::TYPE_REGULAR,
            JmhzSubmissionGuidPolicy::FORM_NONE,
        );
    }

    public function testRegularSubmissionRefusesAmendmentForms(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/jen součásti typu R/');
        $this->policy->forForm(
            JmhzSubmissionFlagMatrix::TYPE_REGULAR,
            JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
            JmhzSubmissionGuidPolicy::FORM_ACCEPTED,
        );
    }
}
