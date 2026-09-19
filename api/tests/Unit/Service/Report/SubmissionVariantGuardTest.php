<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\SubmissionVariantGuard;
use PHPUnit\Framework\TestCase;

/**
 * Pravidla pro volbu typu podání — čistá logika bez databáze.
 *
 * Integrační test hlídá, že builder bránu VOLÁ; tenhle hlídá, co brána říká, včetně
 * směru, který v integračním testu nejde nasimulovat (dnešek proti lhůtě testovacího
 * období v budoucnu).
 */
final class SubmissionVariantGuardTest extends TestCase
{
    /** Opravné tvrzení (§ 138, § 101f/1) patří JEN před lhůtu pro řádné. */
    public function testCorrectiveAfterDeadlineWarns(): void
    {
        $warnings = SubmissionVariantGuard::deadlineWarnings(
            'O',
            '2026-09-25',
            'OPRAVNÉ přiznání',
            'DODATEČNÉ přiznání',
            '§ 138 DŘ',
            today: '2026-09-26',
        );

        self::assertCount(1, $warnings);
        self::assertStringContainsString('DODATEČNÉ přiznání', $warnings[0]);
    }

    public function testCorrectiveBeforeDeadlineIsSilent(): void
    {
        self::assertSame([], SubmissionVariantGuard::deadlineWarnings(
            'O',
            '2026-09-25',
            'OPRAVNÉ přiznání',
            'DODATEČNÉ přiznání',
            '§ 138 DŘ',
            today: '2026-09-25',
        ), 'V den lhůty je opravné pořád na místě.');
    }

    /** Dodatečné/následné patří až PO lhůtě — dokud běží, opravuje se opravným. */
    public function testFollowUpBeforeDeadlineWarns(): void
    {
        foreach (['D', 'N', 'E'] as $forma) {
            $warnings = SubmissionVariantGuard::deadlineWarnings(
                $forma,
                '2026-09-25',
                'OPRAVNÉ hlášení',
                'NÁSLEDNÉ hlášení',
                '§ 101f odst. 1',
                today: '2026-09-20',
            );
            self::assertCount(1, $warnings, "Forma {$forma} před lhůtou musí varovat.");
            self::assertStringContainsString('OPRAVNÉ hlášení', $warnings[0]);
        }
    }

    public function testRegularSubmissionIsNeverJudgedByDeadline(): void
    {
        self::assertSame([], SubmissionVariantGuard::deadlineWarnings(
            'B',
            '2026-09-25',
            'OPRAVNÉ',
            'DODATEČNÉ',
            '§ 138 DŘ',
            today: '2027-01-01',
        ), 'Řádné tvrzení po lhůtě je opožděné, ne špatně zvolené — to hlídá jiná cesta.');
    }

    public function testMissingDeadlineIsSilent(): void
    {
        self::assertSame([], SubmissionVariantGuard::deadlineWarnings('O', '', 'a', 'b', 'c', today: '2026-09-26'));
    }

    /** Perioda tvrzení proti registrované periodě plátce. */
    public function testPeriodMismatchWarns(): void
    {
        $warnings = SubmissionVariantGuard::periodMismatchWarnings('monthly', 'quarterly');

        self::assertCount(1, $warnings);
        self::assertStringContainsString('čtvrtletní', $warnings[0]);
        self::assertStringContainsString('měsíční', $warnings[0]);
    }

    public function testMatchingOrUnknownPeriodIsSilent(): void
    {
        self::assertSame([], SubmissionVariantGuard::periodMismatchWarnings('monthly', 'monthly'));
        self::assertSame([], SubmissionVariantGuard::periodMismatchWarnings('quarterly', 'quarterly'));
        self::assertSame([], SubmissionVariantGuard::periodMismatchWarnings('monthly', ''), 'Bez registrované periody se nehádáme.');
    }
}
