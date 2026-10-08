<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Service\Mail\ClientEmailFormat;
use MyInvoice\Service\Validation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Předmět e-mailu a název přiloženého PDF podle klienta (myinvoice#277).
 *
 * Vykreslování ověřují sdílené případy v tests/Fixtures/client-email-format/cases.json,
 * které čte i web/src/utils/__tests__/clientEmailFormat.spec.ts — náhled ve formuláři
 * klienta tak nemůže ukazovat něco jiného, než co pak odejde v e-mailu.
 */
final class ClientEmailFormatTest extends TestCase
{
    /** @return array<string,array{string,array<string,mixed>}> */
    public static function sharedCaseProvider(): array
    {
        $data = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/tests/Fixtures/client-email-format/cases.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $cases = [];
        foreach (['subject', 'attachment'] as $kind) {
            foreach ($data[$kind] as $case) {
                $case['sample'] = array_replace($data['sample'], $case['sample'] ?? []);
                $cases["{$kind}: {$case['label']}"] = [$kind, $case];
            }
        }
        return $cases;
    }

    /** @param array<string,mixed> $case */
    #[DataProvider('sharedCaseProvider')]
    public function testSdilenePripady(string $kind, array $case): void
    {
        $sample = $case['sample'];
        $values = ClientEmailFormat::values(
            ['varsymbol' => $sample['varsymbol'], 'issue_date' => $sample['issueDate'], 'tax_date' => $sample['taxDate']],
            $sample['client'],
            $sample['supplier'],
            $sample['typeLabel'],
        );
        $format = ClientEmailFormat::normalize($case['format']);

        $actual = $kind === 'subject'
            ? ClientEmailFormat::subject($format, $values)
            : ClientEmailFormat::attachmentName($format, $values);

        self::assertSame($case['expected'], $actual);
    }

    public function testDatumSCasemZDatabazeSeBereJenDen(): void
    {
        $values = ClientEmailFormat::values(
            ['varsymbol' => '1', 'issue_date' => '2026-01-31 00:00:00', 'tax_date' => '2025-12-31'],
            'Klient',
            'Dodavatel',
            'Faktura',
        );

        self::assertSame(['01', '2026', '26'], [$values['MM'], $values['YYYY'], $values['YY']]);
        self::assertSame(['12', '2025', '25'], [$values['DUZP_MM'], $values['DUZP_YYYY'], $values['DUZP_YY']]);
    }

    /** @return array<string,array{mixed,bool,string}> */
    public static function invalidFormatProvider(): array
    {
        return [
            'neznámý znak'          => ['Faktura {CISLO}', false, 'Neznámý zástupný znak {CISLO}'],
            'malá písmena'          => ['{vs}', false, 'Neznámý zástupný znak {vs}'],
            'neuzavřená závorka'    => ['Faktura {VS', false, 'neuzavřenou složenou závorku'],
            'víc řádků'             => ["Faktura\n{VS}", false, 'na jednom řádku'],
            'příliš dlouhý předmět' => [str_repeat('a', 201), false, 'nejvýš 200 znaků'],
            'lomítko v názvu'       => ['faktury/{VS}', true, 'nesmí obsahovat znaky'],
            'dvojtečka v názvu'     => ['{VS}:{MM}', true, 'nesmí obsahovat znaky'],
            'příliš dlouhý název'   => [str_repeat('a', 121), true, 'nejvýš 120 znaků'],
            'není text'             => [['pole'], false, 'musí být text'],
        ];
    }

    #[DataProvider('invalidFormatProvider')]
    public function testNeplatnyFormatVraciChybu(mixed $format, bool $fileName, string $message): void
    {
        $max = $fileName ? ClientEmailFormat::ATTACHMENT_NAME_MAX_LENGTH : ClientEmailFormat::SUBJECT_MAX_LENGTH;
        $errors = ClientEmailFormat::errors($format, $max, $fileName);

        self::assertNotSame([], $errors);
        self::assertStringContainsString($message, implode(' ', $errors));
    }

    /** @return array<string,array{?string,bool}> */
    public static function validFormatProvider(): array
    {
        return [
            'nevyplněno'               => [null, true],
            'prázdné'                  => ['  ', true],
            'všechny zástupné znaky'   => ['{VS}{TYP}{KLIENT}{DODAVATEL}{MM}{YYYY}{YY}{DUZP_MM}{DUZP_YYYY}{DUZP_YY}', true],
            'lomítko v předmětu smí'   => ['Faktura {VS} / {MM}', false],
            'diakritika v názvu smí'   => ['Účet_{DUZP_MM}', true],
        ];
    }

    #[DataProvider('validFormatProvider')]
    public function testPlatnyFormatProjde(?string $format, bool $fileName): void
    {
        $max = $fileName ? ClientEmailFormat::ATTACHMENT_NAME_MAX_LENGTH : ClientEmailFormat::SUBJECT_MAX_LENGTH;

        self::assertSame([], ClientEmailFormat::errors($format, $max, $fileName));
    }

    public function testValidaceKlientaHlasiChybuUSpravnehoPole(): void
    {
        $client = ['company_name' => 'Klient', 'street' => 'Ulice 1', 'city' => 'Praha', 'zip' => '11000'];

        $errors = Validation::client($client + [
            'email_subject_format' => 'Faktura {CISLO}',
            'email_attachment_name_format' => 'a/b',
        ]);

        self::assertArrayHasKey('email_subject_format', $errors);
        self::assertArrayHasKey('email_attachment_name_format', $errors);
        self::assertSame([], Validation::client($client + [
            'email_subject_format' => 'Klient_{DUZP_MM}_{DUZP_YYYY}_Dodavatel',
            'email_attachment_name_format' => 'Dodavatel_{DUZP_MM}_{DUZP_YYYY}',
        ]));
    }
}
