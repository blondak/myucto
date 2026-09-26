<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kódy chyb NEMPRI/HZUPN, oznámení zdravotním pojišťovnám a registrací mají
 * popisek v češtině i angličtině.
 *
 * Server píše důvody česky. V anglickém prostředí UI místo české věty ukáže
 * překlad kódu (`payroll.server_codes.<kód>`, viz
 * `web/src/pages/payroll/payrollServerMessage.ts`). Nový kód bez překladu by
 * v angličtině tiše zobrazil češtinu — proto se výčet kódů a slovník potkávají
 * tady.
 */
#[Group('architecture')]
final class PayrollServerCodeI18nTest extends TestCase
{
    /** @var array<string,array{0:string,1:string}> */
    private const SOURCES = [
        'sickness' => ['api/src/Service/Payroll/Submission/Sickness', "/SicknessException\\(\\s*'([a-z_0-9]+)'/"],
        'health' => ['api/src/Service/Payroll/Submission/HealthInsurance', "/HealthNotificationException\\(\\s*'([a-z_0-9]+)'/"],
        'registration' => ['api/src/Service/Payroll/Submission/Registration', "/Exception\\(\\s*'([a-z_0-9]+)'/"],
    ];

    public function testEveryPayrollSubmissionErrorCodeHasCzechAndEnglishLabel(): void
    {
        $root = dirname(__DIR__, 3);
        $codes = [];
        foreach (self::SOURCES as $domain => [$directory, $pattern]) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches);
                foreach ($matches[1] as $code) {
                    $codes[$code] = $domain;
                }
            }
        }
        self::assertGreaterThan(100, count($codes), 'Výčet kódů podání je podezřele krátký.');

        $missing = [];
        foreach (['cs', 'en'] as $locale) {
            $messages = json_decode(
                (string) file_get_contents($root . "/web/src/i18n/{$locale}.json"),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            foreach (array_keys($codes) as $code) {
                $label = $messages['payroll']['server_codes'][$code]
                    ?? $messages['payroll']['jmhz_gate']['codes'][$code]
                    ?? null;
                if (!is_string($label) || trim($label) === '') {
                    $missing[] = "{$locale}: {$code}";
                }
            }
        }

        self::assertSame([], $missing, 'Kódy bez popisku v payroll.server_codes.');
    }
}
