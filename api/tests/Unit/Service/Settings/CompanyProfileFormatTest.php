<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Settings;

use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileException;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileFormat;
use PHPUnit\Framework\TestCase;

final class CompanyProfileFormatTest extends TestCase
{
    public function testSectionsComeInImportOrderAndOnlyKnownOnes(): void
    {
        $sections = CompanyProfileFormat::sections($this->profile([
            'bank_posting_rules' => [],
            'budouci_sekce' => [],
            'company' => ['stock_enabled' => true],
            'dimensions' => ['types' => []],
        ]));

        self::assertSame(['company', 'dimensions', 'bank_posting_rules'], array_keys($sections));
        self::assertSame(['budouci_sekce'], CompanyProfileFormat::unknownSections($this->profile(['budouci_sekce' => []])));
    }

    public function testOnlyFiltersSectionsAndRejectsUnknownName(): void
    {
        $profile = $this->profile(['company' => [], 'posting_rules' => []]);
        self::assertSame(['posting_rules'], array_keys(CompanyProfileFormat::sections($profile, ['posting_rules'])));

        try {
            CompanyProfileFormat::sections($profile, ['neznama']);
            self::fail('Neznámá sekce musí být odmítnuta.');
        } catch (CompanyProfileException $e) {
            self::assertSame('invalid_section', $e->errorCode);
        }
    }

    /** @return iterable<string,array{0:mixed,1:string}> */
    public static function invalidProfiles(): iterable
    {
        yield 'není objekt' => ['text', 'invalid_profile'];
        yield 'cizí formát' => [['format' => 'jiny', 'version' => 1, 'sections' => []], 'invalid_profile'];
        yield 'bez verze' => [['format' => CompanyProfileFormat::FORMAT, 'sections' => []], 'invalid_profile'];
        yield 'novější verze' => [['format' => CompanyProfileFormat::FORMAT, 'version' => CompanyProfileFormat::VERSION + 1, 'sections' => []], 'unsupported_version'];
        yield 'bez sekcí' => [['format' => CompanyProfileFormat::FORMAT, 'version' => 1], 'invalid_profile'];
        yield 'sekce není pole' => [['format' => CompanyProfileFormat::FORMAT, 'version' => 1, 'sections' => ['company' => 'ano']], 'invalid_profile'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidProfiles')]
    public function testInvalidProfileIsRejected(mixed $profile, string $code): void
    {
        try {
            CompanyProfileFormat::sections($profile);
            self::fail('Neplatný profil musí být odmítnut.');
        } catch (CompanyProfileException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    public function testEverySectionHasPermission(): void
    {
        self::assertSame(CompanyProfileFormat::SECTIONS, array_keys(CompanyProfileFormat::SECTION_PERMISSIONS));
    }

    /**
     * @param array<string,mixed> $sections
     * @return array<string,mixed>
     */
    private function profile(array $sections): array
    {
        return ['format' => CompanyProfileFormat::FORMAT, 'version' => CompanyProfileFormat::VERSION, 'sections' => $sections];
    }
}
