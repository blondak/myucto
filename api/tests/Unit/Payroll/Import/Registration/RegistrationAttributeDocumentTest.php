<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Registration;

use MyInvoice\Service\Payroll\Import\Registration\RegistrationAttributeDocument;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use PHPUnit\Framework\TestCase;

final class RegistrationAttributeDocumentTest extends TestCase
{
    public function testSentenceFromAttributesIsValidRegzecInSchemaOrder(): void
    {
        // Atributy v pořadí, jak je uloží PAMICA: vztah a zaměstnavatel před osobou.
        $attributes = [];
        foreach ([
            [10228, '1234567890123'], [10239, '1'], [10227, '1.3.2025'], [10407, 'false'],
            [10120, 'Syntetická firma'], [10221, '1234567890'],
            [10053, 'Testovací'], [10054, 'Jana'], [10056, '4.5.1990'], [10059, 'Ž'], [10067, 'CZ'],
            [10115, 'N'], [10004, '712'], [10009, '1.4.2026'], [10102, ''], [99999, 'neznámý'],
        ] as [$id, $value]) {
            $attributes[] = ['id' => $id, 'order' => 0, 'value' => $value];
        }

        $document = RegistrationAttributeDocument::sentence($attributes, [
            RegistrationAttributeDocument::SENTENCE => '3',
            RegistrationAttributeDocument::ACTION => '1',
            RegistrationAttributeDocument::PREPARED_ON => '2026-04-09',
        ]);

        $schema = (new PayrollRegistrationSchemaCatalog())->schemaFor('REGZEC25');
        $previous = libxml_use_internal_errors(true);
        $valid = $document->schemaValidate($schema['path']);
        $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::assertTrue($valid, implode("\n", $errors));

        $employee = $document->getElementsByTagNameNS($schema['namespace'], 'employee')->item(0);
        self::assertInstanceOf(\DOMElement::class, $employee);
        self::assertSame(['3', '1', '2026-04-09', '712', '2026-04-01'], [
            $employee->getAttribute('sqnr'), $employee->getAttribute('act'), $employee->getAttribute('dat'),
            $employee->getAttribute('dep'), $employee->getAttribute('fro'),
        ]);
        $children = [];
        foreach ($employee->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $children[] = $child->localName;
            }
        }
        self::assertSame(['client', 'comp', 'job', 'pens'], $children, 'Elementy jdou v pořadí XSD, prázdná pojišťovna se nezapíše.');
        $job = $employee->getElementsByTagNameNS($schema['namespace'], 'job')->item(0);
        self::assertInstanceOf(\DOMElement::class, $job);
        self::assertSame(['2025-03-01', 'N'], [$job->getAttribute('contractfro'), $job->getAttribute('cont')]);
    }
}
