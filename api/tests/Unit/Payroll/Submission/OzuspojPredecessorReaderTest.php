<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Import\Ozuspoj\OzuspojXmlReader;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojException;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSubmissionKind;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojXmlPayload;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojXmlSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Čtečka převzaté datové věty OZUSPOJ23 a dolní mez oznámení záměru podle
 * § 7a odst. 5 věty druhé. Všechna data jsou syntetická.
 */
final class OzuspojPredecessorReaderTest extends TestCase
{
    public function testReadsTheIntentFromAPredecessorFile(): void
    {
        $xml = $this->xml(OzuspojSubmissionKind::Start, '2026-02-01', null);

        self::assertTrue(OzuspojXmlReader::looksLike($xml));
        $file = (new OzuspojXmlReader())->read($xml);

        self::assertSame(OzuspojSubmissionKind::Start, $file->payload->kind);
        self::assertSame('2026-02-01', $file->payload->intentFrom);
        self::assertNull($file->payload->intentTo);
        self::assertSame(112, $file->payload->osszCode);
        self::assertSame('1234567890', $file->payload->employerVariableSymbol);
        self::assertSame('7001010126', $file->payload->employeeBirthNumber);
        self::assertSame(hash('sha256', $xml), $file->sha256);
    }

    public function testEndNoticeWithStartDateIsRefused(): void
    {
        $xml = str_replace(
            '<typPodani>1</typPodani>',
            '<typPodani>2</typPodani>',
            $this->xml(OzuspojSubmissionKind::Start, '2026-02-01', null),
        );

        $this->expectException(OzuspojException::class);
        (new OzuspojXmlReader())->read($xml);
    }

    public function testOtherXmlIsNotRecognised(): void
    {
        self::assertFalse(OzuspojXmlReader::looksLike('<?xml version="1.0"?><jine/>'));
    }

    /** § 7a odst. 5 věta druhá: ne dříve než dnem podání přihlášky. */
    public function testRegistrationMovesTheEarliestNotificationDay(): void
    {
        $window = (new OzuspojDeadlinePolicy())->forIntentStart('2026-11-01', '2026-10-28');

        self::assertSame('2026-10-28', $window->earliestNotificationOn);
    }

    public function testRegistrationAfterTheNotificationDeadlineIsReportedClearly(): void
    {
        try {
            (new OzuspojDeadlinePolicy())->forIntentStart('2026-01-01', '2026-03-02');
            self::fail('Přihláška po lhůtě oznámení musí být srozumitelná chyba.');
        } catch (OzuspojException $exception) {
            self::assertSame('ozuspoj_registration_after_notification_due', $exception->validationCode);
        }
    }

    private function xml(OzuspojSubmissionKind $kind, ?string $from, ?string $to): string
    {
        return (new OzuspojXmlSerializer())->serialize(new OzuspojXmlPayload(
            kind: $kind,
            osszCode: 112,
            intentFrom: $from,
            intentTo: $to,
            employerVariableSymbol: '1234567890',
            employerIdentificationNumber: '00000019',
            employerName: 'Syntetický zaměstnavatel',
            employeeFirstName: 'Zaměstnanec',
            employeeLastName: 'Slevový',
            employeeBirthDate: '1970-01-01',
            employeeBirthNumber: '7001010126',
            productName: 'Syntetický mzdový program',
            productVersion: '1.0',
        ));
    }
}
