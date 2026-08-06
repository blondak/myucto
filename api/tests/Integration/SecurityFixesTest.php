<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\PohodaXmlParser;
use MyInvoice\Service\Mail\SafeLogoPath;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Verifikuje opravy z security report @andrejtomci (3.5.1 release).
 *
 * Nálezy:
 *   #1 BankTx cross-tenant IDOR (match/ignore/unmatch missing supplier scope)
 *   #2 LFI přes logo_path mass-assignment + unguarded preview
 *   #3 HTML injection via {{ intro|raw }} a varsymbol bez charset validace
 *   #4 WorkReport project_id cross-supplier
 *
 * Tento test ověřuje **negative cases** — že útok teď selže. Volá přímo služby
 * (ne přes HTTP), takže nepotřebuje běžící server, jen DB.
 */
#[Group('integration')]
final class SecurityFixesTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $app = Bootstrap::buildApp();
            $container = $app->getContainer();
            if ($container === null) {
                $this->markTestSkipped('Container not available');
            }
            $this->db = $container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        // Uvolni MySQL connection (per-metodu container → kumulace → max_connections).
        if (isset($this->db)) $this->db->close();
    }

    /**
     * #2 — SafeLogoPath rejects cfg.php exfiltration attempt
     */
    public function testSafeLogoPathRejectsCfgExfil(): void
    {
        self::assertNull(SafeLogoPath::resolve('cfg.php', 1));
        self::assertNull(SafeLogoPath::resolve('../cfg.php', 1));
        self::assertNull(SafeLogoPath::resolve('storage/supplier-logos/../../cfg.php', 1));
    }

    /**
     * #2 — SettingsAction mass-assignment whitelist nesmí obsahovat logo_path / signature_path
     */
    public function testSettingsActionMassAssignDoesNotIncludeLogoPath(): void
    {
        $code = file_get_contents(dirname(__DIR__, 3) . '/api/src/Action/Settings/SettingsAction.php');
        self::assertIsString($code);

        // Najdi $allowed array — najdeme jen relevantní řádky uvnitř updateSupplierById
        $start = strpos($code, 'updateSupplierById');
        $end   = strpos($code, "\n    }", $start);
        $methodBody = substr($code, $start, $end - $start);

        // V allowed seznamu (mass-assign whitelist) nesmí být logo_path ani signature_path
        // jako keys/values. Použijeme jednoduchou substring check.
        self::assertStringNotContainsString("'logo_path'", $methodBody,
            'logo_path nesmí být v mass-assign whitelist (LFI vektor, security #2)');
        self::assertStringNotContainsString("'signature_path'", $methodBody,
            'signature_path nesmí být v mass-assign whitelist (parity-sink LFI)');
    }

    /**
     * #3 — Email šablony invoice_send.*.html.twig už nesmí používat `intro|raw`
     */
    public function testEmailTemplatesDoNotUseIntroRaw(): void
    {
        $tpls = [
            dirname(__DIR__, 3) . '/api/templates/email/invoice_send.cs.html.twig',
            dirname(__DIR__, 3) . '/api/templates/email/invoice_send.en.html.twig',
        ];
        foreach ($tpls as $tpl) {
            $content = file_get_contents($tpl);
            self::assertIsString($content);
            self::assertStringNotContainsString('intro|raw', $content,
                "Šablona $tpl nesmí používat {{ intro|raw }} (HTML injection vektor, security #3)");
            // Bonus: varsymbol musí být v safe Twig kontextu (autoescape on)
            self::assertStringContainsString('{{ invoice.varsymbol }}', $content,
                "Šablona musí používat {{ invoice.varsymbol }} v autoescape kontextu");
        }
    }

    /**
     * #3 — InvoiceImportService musí validovat charset varsymbolu (gateway fix)
     *
     * Pravidlo se přestěhovalo do {@see PohodaXmlParser::VARSYMBOL_PATTERN}: parser podle
     * něj pozná, kdy sáhnout po náhradě VS (GUID ze SuperFaktury), a import podle TÉHOŽ
     * pravidla validuje. Guard proto hlídá obojí — že SSOT je pořád ten allowlist a že
     * ho volající skutečně volá. Druhá kopie regexu v importu je zakázaná: rozešly by se
     * a parser by nabízel náhradu za hodnoty, které import bere (nebo naopak).
     */
    public function testImportServiceValidatesVarsymbolCharset(): void
    {
        // Chování SSOT — silnější než grep: allowlist musí reálně odmítnout injection
        // vektory, kvůli kterým omezení vzniklo (varsymbol teče do e-mailů, PDF/ZIP
        // názvů a CSV buněk).
        foreach (['FA2026001', 'a', 'A-b_9', '12345678901234567890'] as $ok) {
            self::assertTrue(PohodaXmlParser::isAcceptableVarsymbol($ok), "Legitimní VS '{$ok}' musí projít");
        }
        foreach ([
            '',
            '123456789012345678901',                // 21 znaků = přes limit DB sloupce
            '<a href=//evil>x</a>',
            '=cmd|\' /C calc\'!A0',
            "FA\n2026",
            'FA 2026',
            'FA/2026',
            '3f2a91c4-7b1d-4e52-9a08-2c6f5d0b1e77',  // GUID (36 znaků)
        ] as $bad) {
            self::assertFalse(PohodaXmlParser::isAcceptableVarsymbol($bad),
                'Allowlist musí odmítnout ' . var_export($bad, true) . ' (security #3)');
        }

        // SSOT je pořád TEN allowlist (A-Z, a-z, 0-9, _, -, max 20) — ne uvolněný regex,
        // který by testům výše vyhověl jinou cestou.
        $parser = file_get_contents(dirname(__DIR__, 3) . '/api/src/Service/Import/PohodaXmlParser.php');
        self::assertIsString($parser);
        self::assertStringContainsString("'/^[A-Za-z0-9_-]{1,20}\$/'", $parser,
            'VARSYMBOL_PATTERN musí zůstat allowlistem A-Z, a-z, 0-9, _, - (security #3)');

        // A brána ho musí volat — import bez validace by SSOT obešel.
        $code = file_get_contents(dirname(__DIR__, 3) . '/api/src/Service/Import/InvoiceImportService.php');
        self::assertIsString($code);
        self::assertStringContainsString('PohodaXmlParser::isAcceptableVarsymbol($varsymbol)', $code,
            'processOne() musí validovat varsymbol přes sdílený allowlist (security #3)');
        self::assertStringNotContainsString("preg_match('/^[A-Za-z0-9_-]{1,20}\$/'", $code,
            'Import nesmí mít vlastní kopii regexu — dvě kopie pravidla se rozejdou (SSOT)');
    }

    /**
     * #1 — BankStatementAction::ignore musí volat ActivityLogger (forensic trace)
     */
    public function testBankIgnoreWritesActivityLog(): void
    {
        $code = file_get_contents(dirname(__DIR__, 3) . '/api/src/Action/Bank/BankStatementAction.php');
        self::assertIsString($code);

        // Najdi `ignore` method
        $start = strpos($code, 'public function ignore(');
        self::assertNotFalse($start, 'ignore() metoda musí existovat');
        $end = strpos($code, "\n    }", $start);
        $methodBody = substr($code, $start, $end - $start);

        self::assertStringContainsString('bank.tx_ignore', $methodBody,
            'ignore() musí logovat bank.tx_ignore action (forensic, security #1)');
        self::assertStringContainsString('logger->log', $methodBody,
            'ignore() musí volat ActivityLogger (security #1)');
        self::assertStringContainsString('txBelongsToCurrentSupplier', $methodBody,
            'ignore() musí ověřit ownership tx (security #1 IDOR)');
    }

    /**
     * #1 — match a unmatch také musí ověřit tx ownership
     */
    public function testBankMutationsCheckSupplierScope(): void
    {
        $code = file_get_contents(dirname(__DIR__, 3) . '/api/src/Action/Bank/BankStatementAction.php');
        self::assertIsString($code);

        foreach (['manualMatch', 'unmatch', 'ignore'] as $method) {
            $start = strpos($code, "public function $method(");
            self::assertNotFalse($start, "$method() metoda musí existovat");
            $end = strpos($code, "\n    }", $start);
            $body = substr($code, $start, $end - $start);
            self::assertStringContainsString('txBelongsToCurrentSupplier', $body,
                "$method() musí ověřit tx ownership (security #1)");
        }
    }

    /**
     * #2 (DiD) — InvoicePdfRenderer::resolveLogoPath musí použít SafeLogoPath
     */
    public function testPdfRendererUsesSafeLogoPath(): void
    {
        $code = file_get_contents(dirname(__DIR__, 3) . '/api/src/Service/Pdf/InvoicePdfRenderer.php');
        self::assertIsString($code);

        $start = strpos($code, 'private function resolveLogoPath(');
        self::assertNotFalse($start, 'resolveLogoPath() musí existovat');
        $end = strpos($code, "\n    }", $start);
        $body = substr($code, $start, $end - $start);

        self::assertStringContainsString('SafeLogoPath::resolve', $body,
            'resolveLogoPath() musí použít SafeLogoPath (security #2 DiD)');
    }

    /**
     * #4 — SaveWorkReportAction musí validovat project ownership (project_id supplier scope)
     */
    public function testWorkReportValidatesProjectOwnership(): void
    {
        $code = file_get_contents(dirname(__DIR__, 3) . '/api/src/Action/WorkReport/SaveWorkReportAction.php');
        self::assertIsString($code);
        self::assertStringContainsString('ProjectRepository', $code,
            'SaveWorkReportAction musí mít DI na ProjectRepository (security #4)');
        self::assertStringContainsString('SupplierGuard::owns($request, $project)', $code,
            'Project ownership check musí být v SaveWorkReportAction (security #4)');
    }
}
