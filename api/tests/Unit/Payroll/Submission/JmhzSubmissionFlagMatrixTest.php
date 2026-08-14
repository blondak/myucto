<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionFlagMatrix;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use PHPUnit\Framework\TestCase;

final class JmhzSubmissionFlagMatrixTest extends TestCase
{
    /**
     * Obr. 28 pravidel podání má sedmnáct řádků. Kdyby se tabulka opsala
     * neúplně, chyběl by nejspíš právě ten řádek, který se v provozu potká
     * jednou za rok — proto se hlídá počet i otisk.
     */
    public function testMatrixKeepsAllSeventeenDocumentedCombinations(): void
    {
        self::assertSame(17, JmhzSubmissionFlagMatrix::rowCount());
        self::assertSame(
            JmhzSubmissionFlagMatrix::MATRIX_SHA256,
            JmhzSubmissionFlagMatrix::fingerprint(),
        );
    }

    public function testRegularSubmissionMustCarryAllThreeParts(): void
    {
        JmhzSubmissionFlagMatrix::assertAllowed('R', true, true, ['R']);

        $this->expectException(JmhzXmlException::class);
        JmhzSubmissionFlagMatrix::assertAllowed('R', false, true, ['R']);
    }

    public function testAmendmentMayCarryOnlyTheSummaryPart(): void
    {
        JmhzSubmissionFlagMatrix::assertAllowed('O', true, false, []);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Opravné podání bez jediné části není oprava ničeho. Kombinace v tabulce
     * chybí a musí se odmítnout, ne projít jako prázdné podání.
     */
    public function testEmptyAmendmentIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/Kombinace příznaků není povolená/');
        JmhzSubmissionFlagMatrix::assertAllowed('O', false, false, []);
    }

    public function testCancellationCarriesNothing(): void
    {
        JmhzSubmissionFlagMatrix::assertAllowed('S', false, false, []);

        $this->expectException(JmhzXmlException::class);
        JmhzSubmissionFlagMatrix::assertAllowed('S', false, false, ['R']);
    }

    /**
     * Jedno opravné hlášení smí nést zároveň opravu i storno součásti; každá
     * kombinace se ale posuzuje samostatně.
     */
    public function testAmendmentMayMixCorrectionAndCancellationForms(): void
    {
        JmhzSubmissionFlagMatrix::assertAllowed('O', true, true, ['O', 'S']);
        $this->expectNotToPerformAssertions();
    }

    public function testUnknownSubmissionTypeIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/není v pravidlech JMHZ definovaný/');
        JmhzSubmissionFlagMatrix::assertAllowed('X', true, true, ['R']);
    }
}
