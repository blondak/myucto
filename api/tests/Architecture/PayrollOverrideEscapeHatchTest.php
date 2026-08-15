<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Routes;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Payroll\Run\PayrollRunValidationOverrideService;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

/**
 * Varování, které blokuje mzdy, musí mít cestu ven.
 *
 * ── Co se opravovalo ───────────────────────────────────────────────────────────
 * {@see \MyInvoice\Service\Payroll\Run\PayrollRunWorkflow} odmítá `approve`,
 * dokud je `unresolvedOverrideCount > 0`. Sloupce, které ten počet nulují
 * (`override_reason`, `overridden_by`, `overridden_at`), existují od migrace
 * 1210 — ale routa ani obrazovka k nim nikdy nevedly. Každé varování
 * s `requires_override = 1` proto mzdový běh zablokovalo natrvalo a jediná
 * cesta ven byla ruční UPDATE v databázi. Kvůli té pasti musela kontrola
 * přesčasů zůstat u `requires_override = 0`, ačkoli tam schválení výjimky
 * dává smysl.
 *
 * ── Co tenhle test hlídá ───────────────────────────────────────────────────────
 * Že ta půlka nezmizí znovu. Dokud workflow na nevyřešeném overridu zastavuje
 * schválení, musí v aplikaci existovat endpoint, kterým ho jde vyřešit —
 * a musí mít autorizační politiku, ne jen viset v routeru.
 */
final class PayrollOverrideEscapeHatchTest extends TestCase
{
    private const GRANT_PATH = '/api/payroll/runs/7/validations/9/override';

    public function testWorkflowStillBlocksApprovalOnUnresolvedOverrides(): void
    {
        $workflow = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Service/Payroll/Run/PayrollRunWorkflow.php',
        );

        // Kdyby tahle podmínka zmizela, celý guard níž ztrácí smysl a test se
        // musí přepsat vědomě, ne tiše přestat něco hlídat.
        self::assertStringContainsString(
            'unresolvedOverrideCount',
            $workflow,
            'Podmínka schválení běhu se změnila — zkontroluj, jestli tenhle guard ještě platí.',
        );
    }

    public function testThereIsARouteThatResolvesAnOverride(): void
    {
        $app = AppFactory::create();
        Routes::register($app);
        $patterns = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $patterns[] = $method . ' ' . $route->getPattern();
            }
        }

        self::assertContains(
            'POST /api/payroll/runs/{id:[0-9]+}/validations/{validationId:[0-9]+}/override',
            $patterns,
            'Bez routy pro schválení výjimky je varování s requires_override neodklidnutelné.',
        );
        self::assertContains(
            'DELETE /api/payroll/runs/{id:[0-9]+}/validations/{validationId:[0-9]+}/override',
            $patterns,
            'Schválenou výjimku musí jít vzít zpět, dokud běh není schválený.',
        );
    }

    public function testOverrideRoutesRequireThePayrollApprovalPermission(): void
    {
        $map = new RoutePermissionMap();
        foreach (['POST', 'DELETE'] as $method) {
            $policy = $map->match($method, self::GRANT_PATH);
            self::assertNotNull($policy, $method . ' na výjimku nemá autorizační politiku.');
            self::assertSame(
                'payroll.approve',
                $policy->key,
                'Schválení výjimky je věcně část schválení mzdy — nesmí spadnout pod slabší právo.',
            );
            self::assertSame(AccessLevel::WRITE, $policy->minimum);
        }
    }

    /**
     * Zápis do override sloupců smí dělat jediná služba. Kdyby si ho někdo
     * zopakoval jinde, obešel by povinné odůvodnění i auditní stopu.
     */
    public function testOnlyTheOverrideServiceWritesTheOverrideColumns(): void
    {
        $writers = [];
        $root = dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/overridden_at\s*=/u', $source) === 1) {
                $writers[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
        sort($writers);

        self::assertSame(
            ['Repository/Payroll/PayrollRunRepository.php'],
            $writers,
            'Do override sloupců smí sahat jen repozitář volaný z '
                . PayrollRunValidationOverrideService::class . '.',
        );
    }
}
