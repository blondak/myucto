<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Hlídá, že `openapi.yaml` a {@see \MyInvoice\Middleware\ApiScopeMiddleware} mluví
 * o téže hranici.
 *
 * Audit spec našel 69 operací, které dokumentace slibovala, ale bearer token na ně
 * dostal 403 — a integrátor to zjistil až za běhu. Část byla chyba v allowlistu
 * (`#^/api/tax(/|$)#` nechytal `/api/tax-return/…`), část záměr, který ale ve spec
 * nikde nestál.
 *
 * Test nevynucuje, CO má být tokenu dostupné — to je rozhodnutí. Vynucuje, aby to
 * spec u každé operace ŘEKLA: co token nezavolá, musí deklarovat 403.
 *
 * Pozor na dosah: kontroluje se jen přítomnost kódu 403, ne jeho důvod. Operace,
 * která už 403 deklaruje kvůli oprávnění role, tímhle testem projde, i kdyby
 * z allowlistu vypadla. Proto má vzor pro přiznání ještě vlastní cílené tvrzení níže.
 *
 * YAML se čte řádkovým parserem, stejně jako v `cmd/check-openapi-coverage.php` —
 * repo nemá YAML knihovnu ani `ext-yaml`.
 */
final class BearerScopeOpenApiContractTest extends TestCase
{
    private const READ_METHODS = ['get', 'head', 'options'];
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

    public function testUnreachableOperationsDeclareForbidden(): void
    {
        $allowed  = $this->patterns('BEARER_ALLOWED');
        $readOnly = $this->patterns('BEARER_READ_ONLY');
        self::assertNotEmpty($allowed, 'BEARER_ALLOWED se nepodařilo přečíst.');
        self::assertNotEmpty($readOnly, 'BEARER_READ_ONLY se nepodařilo přečíst.');

        $operations = $this->operations();
        // Pojistka proti tiše zelenému testu: kdyby řádkový parser přestal spec číst
        // (změna odsazení, přeformátování), prošlo by tvrzení níže na prázdném seznamu.
        self::assertGreaterThan(700, count($operations), 'Parser spec nenašel očekávaný počet operací.');
        $withCodes = array_filter($operations, static fn (array $op): bool => $op[2] !== []);
        self::assertGreaterThan(700, count($withCodes), 'Parser nenašel status kódy operací.');

        $missingEndpoint = [];
        $missingWrite    = [];

        foreach ($operations as [$path, $method, $codes]) {
            // Cesty ve spec jsou /api/v1/…; middleware je vidí až po přepisu na /api/….
            $runtimePath = '/api' . substr($path, strlen('/api/v1'));

            if (!$this->pathMatches($runtimePath, $allowed)) {
                if (!in_array('403', $codes, true)) {
                    $missingEndpoint[] = strtoupper($method) . ' ' . $path;
                }
                continue;
            }

            if (in_array($method, self::READ_METHODS, true)) {
                continue;
            }
            if ($this->pathMatches($runtimePath, $readOnly) && !in_array('403', $codes, true)) {
                $missingWrite[] = strtoupper($method) . ' ' . $path;
            }
        }

        self::assertSame([], $missingEndpoint, sprintf(
            "Operace nedostupné přes API token musí ve spec deklarovat 403 token_endpoint_forbidden:\n  %s",
            implode("\n  ", $missingEndpoint),
        ));
        self::assertSame([], $missingWrite, sprintf(
            "Zápisové operace v účetní/daňové vrstvě musí deklarovat 403 token_write_forbidden:\n  %s",
            implode("\n  ", $missingWrite),
        ));
    }

    /**
     * Daňová přiznání patří do allowlistu vlastním vzorem. Kdyby se někdo pokusil
     * spolehnout na `#^/api/tax(/|$)#`, spadne to tady — ten vzor `tax-return`
     * nikdy nechytí, protože po „tax" vyžaduje lomítko.
     */
    public function testTaxReturnsHaveTheirOwnAllowlistPattern(): void
    {
        $middleware = $this->read('api/src/Middleware/ApiScopeMiddleware.php');
        self::assertStringContainsString("'#^/api/tax-return(/|\$)#'", $middleware);
        self::assertSame(
            0,
            preg_match('#^/api/tax(/|$)#', '/api/tax-return/dppo/2026'),
            'Vzor pro /api/tax nesmí zastupovat /api/tax-return — proto má přiznání vlastní.',
        );
    }

    /** @return list<string> */
    private function patterns(string $const): array
    {
        $php = $this->read('api/src/Middleware/ApiScopeMiddleware.php');
        $block = explode('];', explode('private const ' . $const . ' = [', $php)[1] ?? '')[0] ?? '';
        preg_match_all("/'(#.+?#)'/", $block, $m);

        return $m[1] ?? [];
    }

    /** @param list<string> $patterns */
    private function pathMatches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Operace veřejného API se seznamem deklarovaných status kódů.
     *
     * @return list<array{0:string,1:string,2:list<string>}>
     */
    private function operations(): array
    {
        $lines = explode("\n", $this->read('api/openapi.yaml'));
        $methods = implode('|', self::METHODS);

        $out = [];
        $path = null;
        $method = null;
        $codes = [];
        $inResponses = false;

        $flush = static function () use (&$out, &$path, &$method, &$codes): void {
            if ($path !== null && $method !== null) {
                $out[] = [$path, $method, $codes];
            }
            $method = null;
            $codes = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^  (\/\S+):\s*$/', $line, $m) === 1) {
                $flush();
                $path = str_starts_with($m[1], '/api/v1/') ? $m[1] : null;
                $inResponses = false;
                continue;
            }
            if ($path === null) {
                continue;
            }
            if (preg_match('/^    (' . $methods . '):\s*$/', $line, $m) === 1) {
                $flush();
                $method = $m[1];
                $inResponses = false;
                continue;
            }
            if ($method === null) {
                continue;
            }
            if ($line === '      responses:') {
                $inResponses = true;
                continue;
            }
            // Klíč na úrovni operace (requestBody, tags, …) ukončuje blok responses.
            if (preg_match('/^      \S/', $line) === 1) {
                $inResponses = false;
                continue;
            }
            if ($inResponses && preg_match("/^        '?(\d{3})'?:/", $line, $m) === 1) {
                $codes[] = $m[1];
            }
        }
        $flush();

        return $out;
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertIsString($contents);

        return $contents;
    }
}
