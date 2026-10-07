<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Sickness\NempriCodebook;
use PHPUnit\Framework\TestCase;

/**
 * Nabídka na obrazovce případů dávek a kontrola na serveru čtou tytéž
 * číselníky ČSSZ. Rozjetý seznam by nabízel kód, který server odmítne,
 * nebo naopak schoval kód, který ČSSZ přijímá.
 */
final class NempriCodebookContractTest extends TestCase
{
    public function testFrontendCodebooksMatchServerCodebooks(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 5) . '/web/src/pages/payroll/nempriCodebooks.ts');

        self::assertSame(NempriCodebook::PATERNITY_REASONS, $this->list($source, 'PATERNITY_REASONS'));
        self::assertSame(NempriCodebook::MATERNITY_CARE_REASONS, $this->list($source, 'MATERNITY_CARE_REASONS'));
        self::assertSame(NempriCodebook::FAMILY_RELATIONSHIPS, $this->list($source, 'FAMILY_RELATIONSHIPS'));
        self::assertSame(NempriCodebook::CARE_RELATIONSHIPS, $this->list($source, 'CARE_RELATIONSHIPS'));
        self::assertSame(NempriCodebook::PENSION_KINDS, $this->list($source, 'PENSION_KINDS'));
    }

    public function testEveryCodebookValueHasCzechAndEnglishLabel(): void
    {
        foreach (['cs', 'en'] as $locale) {
            $messages = json_decode(
                (string) file_get_contents(dirname(__DIR__, 5) . "/web/src/i18n/{$locale}.json"),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $codebooks = $messages['payroll']['sicknessCases']['codebooks'] ?? null;
            self::assertIsArray($codebooks, "Chybí payroll.sicknessCases.codebooks v {$locale}.json.");
            foreach ([
                'paternityReasons' => NempriCodebook::PATERNITY_REASONS,
                'maternityCareReasons' => NempriCodebook::MATERNITY_CARE_REASONS,
                'familyRelationships' => NempriCodebook::FAMILY_RELATIONSHIPS,
                'careRelationships' => NempriCodebook::CARE_RELATIONSHIPS,
                'pensionKinds' => NempriCodebook::PENSION_KINDS,
            ] as $group => $codes) {
                foreach ($codes as $code) {
                    self::assertArrayHasKey($code, $codebooks[$group], "{$locale}: {$group}.{$code}");
                    self::assertStringStartsWith($code . ' ', (string) $codebooks[$group][$code]);
                }
            }
        }
    }

    /** @return list<string> */
    private function list(string $source, string $name): array
    {
        self::assertSame(
            1,
            preg_match('/export const ' . $name . ' = \[(.*?)\] as const/s', $source, $match),
            "Seznam {$name} v nempriCodebooks.ts nenalezen.",
        );
        preg_match_all("/'([^']+)'/", $match[1], $codes);

        return $codes[1];
    }
}
