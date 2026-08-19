<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MyInvoice\Service\Tenant\ClientRoutePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ClientRoutePolicyDeploymentProtectionTest extends TestCase
{
    private const MANIFEST_PATH = 'shared/client-route-policy.json';
    private const MANIFEST_URI = '/shared/client-route-policy.json';

    public function testBackendAndFrontendResolveTheSameManifest(): void
    {
        $root = self::repoRoot();
        $manifest = realpath($root . '/' . self::MANIFEST_PATH);
        self::assertIsString($manifest, 'Sdílený manifest klientských rout chybí.');

        $reflection = new ReflectionClass(ClientRoutePolicy::class);
        $constant = $reflection->getReflectionConstant('MANIFEST');
        self::assertNotFalse($constant, 'Backend nemá deklarovanou cestu k manifestu.');
        self::assertSame(
            $manifest,
            realpath((string) $constant->getValue()),
            'Backend musí číst jediný sdílený manifest.',
        );

        $frontend = self::read($root . '/web/src/security/clientRoutePolicy.ts');
        self::assertMatchesRegularExpression(
            '/^import routePolicy from [\'\"]@shared\/client-route-policy\.json[\'\"]$/m',
            $frontend,
            'Frontend musí importovat stejný sdílený manifest.',
        );

        $vite = self::read($root . '/web/vite.config.ts');
        self::assertMatchesRegularExpression(
            '/[\'\"]@shared[\'\"]\s*:\s*fileURLToPath\(new URL\([\'\"]\.\.\/shared[\'\"]/',
            $vite,
            'Vite alias @shared musí mířit na kořenovou složku shared.',
        );

        $tsconfig = json_decode(
            self::read($root . '/web/tsconfig.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            ['../shared/*'],
            $tsconfig['compilerOptions']['paths']['@shared/*'] ?? null,
            'TypeScript alias @shared musí odpovídat Vite aliasu.',
        );

        self::assertMatchesRegularExpression(
            '/^\s*[\'\"]\/shared\/client-route-policy\.json[\'\"],$/m',
            self::read($root . '/tools/securitySmokeTest.php'),
            'Živý security smoke test musí hlídat přímé načtení manifestu.',
        );
    }

    /** @return array<string,array{string}> */
    public static function dockerfileProvider(): array
    {
        return [
            'Apache image' => ['Dockerfile'],
            'nginx image' => ['Dockerfile.alpine'],
        ];
    }

    #[DataProvider('dockerfileProvider')]
    public function testDockerBuildKeepsManifestAvailableToBothConsumers(string $filename): void
    {
        $dockerfile = self::read(self::repoRoot() . '/' . $filename);
        $webStageEnd = strpos($dockerfile, '# ---------- Stage 2: composer');
        self::assertNotFalse($webStageEnd, "{$filename} nemá očekávanou web build stage.");
        $webStage = substr($dockerfile, 0, $webStageEnd);

        self::assertMatchesRegularExpression(
            '/^COPY shared\/ \/shared\/$/m',
            $webStage,
            "{$filename} musí zpřístupnit manifest Vite buildu mimo jeho /app workdir.",
        );
        self::assertLessThan(
            strpos($webStage, 'RUN pnpm build'),
            strpos($webStage, 'COPY shared/ /shared/'),
            "{$filename} musí manifest zkopírovat před frontend buildem.",
        );

        $runtimeStart = strpos($dockerfile, ' AS runtime');
        self::assertNotFalse($runtimeStart, "{$filename} nemá očekávanou runtime stage.");
        $runtime = substr($dockerfile, $runtimeStart);
        self::assertMatchesRegularExpression(
            '/WORKDIR \/var\/www\/html[\s\S]*COPY --chown=www-data:www-data \. \./',
            $runtime,
            "{$filename} musí manifest zachovat v runtime image pro PHP konzumenta.",
        );
    }

    public function testManifestIsNotDuplicatedIntoExplicitPublicTrees(): void
    {
        $root = self::repoRoot();
        foreach ([
            'client-route-policy.json',
            'api/public/client-route-policy.json',
            'web/public/client-route-policy.json',
            'web/dist/client-route-policy.json',
            'manual/client-route-policy.json',
        ] as $relativePath) {
            self::assertFileDoesNotExist(
                $root . '/' . $relativePath,
                "Manifest nesmí mít veřejnou kopii {$relativePath}.",
            );
        }
    }

    public function testApacheDeniesDirectManifestRequests(): void
    {
        $configuration = self::read(self::repoRoot() . '/.htaccess');
        preg_match_all(
            '/^\s*RewriteRule\s+(\S+)\s+(\S+)\s+\[([^\]]+)]\s*$/m',
            $configuration,
            $rules,
            PREG_SET_ORDER,
        );

        $denied = false;
        foreach ($rules as $rule) {
            if (preg_match('#' . $rule[1] . '#D', ltrim(self::MANIFEST_URI, '/')) !== 1) {
                continue;
            }
            $flags = array_map('strtoupper', array_map('trim', explode(',', $rule[3])));
            if ($rule[2] === '-' && in_array('F', $flags, true) && in_array('L', $flags, true)) {
                $denied = true;
                break;
            }
        }

        self::assertTrue($denied, 'Apache musí /shared/client-route-policy.json odmítnout před statickým servírováním.');
        self::assertMatchesRegularExpression(
            '/<Files\s+[\'\"]client-route-policy\.json[\'\"]>\s*Require all denied\s*<\/Files>/s',
            $configuration,
            'Apache musí manifest odmítnout i bez mod_rewrite.',
        );
    }

    public function testIisDeniesDirectManifestRequests(): void
    {
        $document = new DOMDocument();
        self::assertTrue(
            $document->load(self::repoRoot() . '/web.config', LIBXML_NONET),
            'IIS web.config není platné XML.',
        );
        $xpath = new DOMXPath($document);
        $rules = $xpath->query('//system.webServer/rewrite/rules/rule');
        self::assertNotFalse($rules);

        $denialIndex = null;
        $fallbackIndex = null;
        foreach ($rules as $index => $rule) {
            self::assertInstanceOf(DOMElement::class, $rule);
            if ($rule->getAttribute('name') === 'SPA fallback') {
                $fallbackIndex = $index;
            }

            $match = $rule->getElementsByTagName('match')->item(0);
            $action = $rule->getElementsByTagName('action')->item(0);
            if (!$match instanceof DOMElement || !$action instanceof DOMElement) continue;

            $pattern = $match->getAttribute('url');
            if ($action->getAttribute('type') === 'CustomResponse'
                && $action->getAttribute('statusCode') === '403'
                && preg_match('#' . $pattern . '#D', ltrim(self::MANIFEST_URI, '/')) === 1
            ) {
                $denialIndex ??= $index;
            }
        }

        self::assertNotNull($denialIndex, 'IIS musí /shared/client-route-policy.json vrátit jako Forbidden.');
        self::assertNotNull($fallbackIndex, 'IIS konfigurace nemá očekávaný SPA fallback.');
        self::assertLessThan($fallbackIndex, $denialIndex, 'IIS blokace musí předcházet SPA fallbacku.');
    }

    public function testNginxDeniesDirectManifestRequests(): void
    {
        $configuration = self::read(self::repoRoot() . '/docker/nginx.conf');
        preg_match_all(
            '/location\s+~\s+([^\s{]+)\s*\{([^{}]*)}/s',
            $configuration,
            $locations,
            PREG_SET_ORDER,
        );

        $denied = false;
        foreach ($locations as $location) {
            if (preg_match('#' . $location[1] . '#D', self::MANIFEST_URI) === 1
                && preg_match('/\breturn\s+403\s*;/', $location[2]) === 1
            ) {
                $denied = true;
                break;
            }
        }

        self::assertTrue($denied, 'nginx musí /shared/client-route-policy.json odmítnout před statickým servírováním.');
        self::assertStringContainsString(
            'COPY docker/nginx.conf /etc/nginx/nginx.conf',
            self::read(self::repoRoot() . '/Dockerfile.alpine'),
            'Alpine image musí testovanou nginx konfiguraci skutečně instalovat.',
        );
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, "Nelze načíst {$path}.");
        // Dockerfile, .htaccess i nginx.conf nemají v .gitattributes vynucené LF,
        // takže Windows checkout (core.autocrlf) je dostane s CRLF. Řádkové
        // regexy níže hlídají obsah konfigurace, ne konce řádků.
        return str_replace("\r\n", "\n", $contents);
    }
}
