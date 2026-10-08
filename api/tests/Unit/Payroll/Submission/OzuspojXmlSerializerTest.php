<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojException;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSubmissionKind;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojXmlPayload;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojXmlValidator;
use PHPUnit\Framework\TestCase;

/**
 * Serializace a validace datové věty OZUSPOJ23.
 *
 * Validátor běží proti PŘIPNUTÉMU oficiálnímu XSD, ne proti očekávanému
 * řetězci — golden test, který jen potvrzuje vlastní odhad tvaru, o shodě
 * se schématem ČSSZ neříká nic.
 */
final class OzuspojXmlSerializerTest extends TestCase
{
    private function payload(
        OzuspojSubmissionKind $kind = OzuspojSubmissionKind::Start,
        ?string $intentFrom = '2026-09-01',
        ?string $intentTo = null,
    ): OzuspojXmlPayload {
        return new OzuspojXmlPayload(
            kind: $kind,
            osszCode: 222,
            intentFrom: $intentFrom,
            intentTo: $intentTo,
            employerVariableSymbol: '1182114205',
            employerIdentificationNumber: '12345678',
            employerName: 'Zkušební firma s.r.o.',
            employeeFirstName: 'Jan',
            employeeLastName: 'Malý',
            employeeBirthDate: '1980-01-01',
            employeeBirthNumber: '8001011117',
            productName: 'MyÚčto.cz',
            productVersion: '1.0.0',
        );
    }

    private function validator(): OzuspojXmlValidator
    {
        return new OzuspojXmlValidator(new OzuspojSchemaCatalog());
    }

    public function testStartNotificationValidatesAgainstThePinnedSchema(): void
    {
        $payload = $this->payload();
        $xml = (new OzuspojXmlSerializer())->serialize($payload);

        self::assertStringContainsString(
            '<typPodani>1</typPodani>',
            $xml,
        );
        self::assertStringContainsString('<datumOd>2026-09-01</datumOd>', $xml);
        self::assertStringNotContainsString('<datumDo>', $xml);
        self::assertStringContainsString(
            'xmlns="' . OzuspojSchemaCatalog::NAMESPACE . '"',
            $xml,
        );

        $this->validator()->validate($payload, $xml);
        $this->addToAssertionCount(1);
    }

    /** Skončení nese `datumDo` a `typPodani` 2; `datumOd` v něm být nesmí. */
    public function testEndNotificationCarriesTheEndDateOnly(): void
    {
        $payload = $this->payload(
            OzuspojSubmissionKind::End,
            null,
            '2026-11-30',
        );
        $xml = (new OzuspojXmlSerializer())->serialize($payload);

        self::assertStringContainsString('<typPodani>2</typPodani>', $xml);
        self::assertStringContainsString('<datumDo>2026-11-30</datumDo>', $xml);
        self::assertStringNotContainsString('<datumOd>', $xml);

        $this->validator()->validate($payload, $xml);
        $this->addToAssertionCount(1);
    }

    public function testCancellationKeepsTheStartDate(): void
    {
        $payload = $this->payload(OzuspojSubmissionKind::Cancellation);
        $xml = (new OzuspojXmlSerializer())->serialize($payload);

        self::assertStringContainsString('<typPodani>3</typPodani>', $xml);
        self::assertStringContainsString('<datumOd>2026-09-01</datumOd>', $xml);

        $this->validator()->validate($payload, $xml);
        $this->addToAssertionCount(1);
    }

    /**
     * XSD tuhle hranici neuhlídá — `datumOd` i `datumDo` jsou v něm
     * `minOccurs="0"` pro všechny tři typy. Pravidlo je jen v popisu datové
     * věty, takže ho musí vynutit validátor.
     */
    public function testEndNotificationWithoutTheEndDateIsRejected(): void
    {
        $payload = $this->payload(OzuspojSubmissionKind::End, null, null);
        $xml = (new OzuspojXmlSerializer())->serialize($payload);

        $this->expectException(OzuspojException::class);
        $this->expectExceptionMessage('musí uvádět den skončení');
        $this->validator()->validate($payload, $xml);
    }

    public function testEndNotificationWithAStartDateIsRejected(): void
    {
        $payload = $this->payload(
            OzuspojSubmissionKind::End,
            '2026-09-01',
            '2026-11-30',
        );
        $xml = (new OzuspojXmlSerializer())->serialize($payload);

        $this->expectException(OzuspojException::class);
        $this->expectExceptionMessage('nesmí uvádět den zahájení');
        $this->validator()->validate($payload, $xml);
    }

    public function testMissingVariableSymbolIsRejectedWithAnActionableMessage(): void
    {
        $base = $this->payload();
        $payload = new OzuspojXmlPayload(
            kind: $base->kind,
            osszCode: $base->osszCode,
            intentFrom: $base->intentFrom,
            intentTo: null,
            employerVariableSymbol: '123',
            employerIdentificationNumber: $base->employerIdentificationNumber,
            employerName: $base->employerName,
            employeeFirstName: $base->employeeFirstName,
            employeeLastName: $base->employeeLastName,
            employeeBirthDate: $base->employeeBirthDate,
            employeeBirthNumber: $base->employeeBirthNumber,
            productName: $base->productName,
            productVersion: $base->productVersion,
        );
        $xml = (new OzuspojXmlSerializer())->serialize($payload);

        $this->expectException(OzuspojException::class);
        $this->expectExceptionMessage('desetimístný variabilní symbol');
        $this->validator()->validate($payload, $xml);
    }

    /** @param array<string,mixed> $overrides */
    private function payloadWith(array $overrides): OzuspojXmlPayload
    {
        $base = $this->payload();
        $values = [
            'kind' => $base->kind,
            'osszCode' => $base->osszCode,
            'intentFrom' => $base->intentFrom,
            'intentTo' => null,
            'employerVariableSymbol' => $base->employerVariableSymbol,
            'employerIdentificationNumber' => $base->employerIdentificationNumber,
            'employerName' => $base->employerName,
            'employeeFirstName' => $base->employeeFirstName,
            'employeeLastName' => $base->employeeLastName,
            'employeeBirthDate' => $base->employeeBirthDate,
            'employeeBirthNumber' => $base->employeeBirthNumber,
            'productName' => $base->productName,
            'productVersion' => $base->productVersion,
        ];

        return new OzuspojXmlPayload(...array_merge($values, $overrides));
    }

    /** OZUSPOJ-rodneCislo-3: XSD rodné číslo nevyžaduje, oznámení ho nést musí. */
    public function testMissingBirthNumberIsRejectedBeforeTheSchema(): void
    {
        $payload = $this->payloadWith(['employeeBirthNumber' => null]);
        $xml = (new OzuspojXmlSerializer())->serialize($payload);

        $this->expectException(OzuspojException::class);
        $this->expectExceptionMessage('rodné číslo ani evidenční číslo pojištěnce');
        $this->validator()->validate($payload, $xml);
    }

    /** OZUSPOJ-kodOSSZ-3: kód 101 je v C_COKR označený „Nepoužívat pro e-podání". */
    public function testCentralOfficeCodeIsRejected(): void
    {
        $payload = $this->payloadWith(['osszCode' => 101]);
        $xml = (new OzuspojXmlSerializer())->serialize($payload);

        $this->expectException(OzuspojException::class);
        $this->expectExceptionMessage('není v číselníku okresů ČSSZ');
        $this->validator()->validate($payload, $xml);
    }

    /** XSD povolí kterýkoli kód 100 až 999, číselník ale ne. */
    public function testCodeOutsideTheCodebookIsRejected(): void
    {
        $payload = $this->payloadWith(['osszCode' => 999]);
        $xml = (new OzuspojXmlSerializer())->serialize($payload);

        $this->expectException(OzuspojException::class);
        $this->expectExceptionMessage('není v číselníku okresů ČSSZ');
        $this->validator()->validate($payload, $xml);
    }

    public function testPragueDistrictCodeIsAccepted(): void
    {
        $payload = $this->payloadWith(['osszCode' => 112]);
        $xml = (new OzuspojXmlSerializer())->serialize($payload);

        $this->validator()->validate($payload, $xml);
        $this->addToAssertionCount(1);
    }

    /** OZUSPOJ-XSD-3: znak mimo baseTypes2.xsd se pojmenuje dřív než hláškou knihovny. */
    public function testNameWithCharacterOutsideTheSchemaListsTheCharacter(): void
    {
        $payload = $this->payloadWith(['employeeLastName' => 'Novák (ml.)']);
        $xml = (new OzuspojXmlSerializer())->serialize($payload);

        try {
            $this->validator()->validate($payload, $xml);
            self::fail('Příjmení se závorkami musí být zamítnuto.');
        } catch (OzuspojException $exception) {
            self::assertSame('ozuspoj_employee_name_characters_invalid', $exception->validationCode);
            self::assertStringContainsString('„(“', $exception->getMessage());
            self::assertStringContainsString('„)“', $exception->getMessage());
            self::assertStringContainsString('příjmení', $exception->getMessage());
        }
    }

    public function testNameWithCzechLettersApostropheAndHyphenIsAccepted(): void
    {
        $payload = $this->payloadWith([
            'employeeFirstName' => 'Jarmila-Žofie',
            'employeeLastName' => "Dvořáková O'Neil, ml.",
        ]);
        $xml = (new OzuspojXmlSerializer())->serialize($payload);

        $this->validator()->validate($payload, $xml);
        $this->addToAssertionCount(1);
    }

    /**
     * Otisk schématu je připnutý. Kdyby se soubor vyměnil, validace se musí
     * zavřít, ne mlčky validovat proti cizímu XSD.
     */
    public function testSchemaCatalogRejectsAnUnknownDocumentType(): void
    {
        $this->expectException(OzuspojException::class);
        $this->expectExceptionMessage('nemá připnuté XSD');
        (new OzuspojSchemaCatalog())->schemaFor('OZUSPOJ99');
    }
}
