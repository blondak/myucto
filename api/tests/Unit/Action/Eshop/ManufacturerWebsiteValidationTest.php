<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Eshop;

use MyInvoice\Action\Eshop\ManufacturerAction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionMethod;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * SEC-10 (2. kolo) — validace pole `website` u výrobce.
 *
 * První kolo zavedlo tvrdý filtr přes {@see \MyInvoice\Support\SafeUrl}. Protože ale
 * formulář posílá při editaci celý objekt zpátky, legacy záznam s neplatnou uloženou
 * adresou by šel natrvalo neuložit — uživatel by u něj nezměnil ani jméno. Tenhle test
 * hlídá kompromis: nezměněná legacy hodnota request nezablokuje, ale ani se nezachová
 * (z DB se smaže); odlišná neplatná hodnota je pořád chyba a create je striktní.
 *
 * Bez DB — voláme privátní `validate()` reflexí na instanci bez konstruktoru.
 */
#[Group('unit')]
final class ManufacturerWebsiteValidationTest extends TestCase
{
    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $existing
     * @return array{0:array<string,mixed>, 1:?ResponseInterface}
     */
    private function validate(array $body, ?array $existing = null): array
    {
        $action = (new \ReflectionClass(ManufacturerAction::class))->newInstanceWithoutConstructor();
        // setAccessible() je od PHP 8.1 bez efektu a od 8.5 deprecated.
        $method = new ReflectionMethod(ManufacturerAction::class, 'validate');

        /** @var array{0:array<string,mixed>, 1:?ResponseInterface} $result */
        $result = $method->invoke($action, (new ResponseFactory())->createResponse(), $body, $existing);
        return $result;
    }

    /** @return array<string,mixed> */
    private function existing(?string $website): array
    {
        return [
            'id'            => 7,
            'code'          => 'ACME',
            'name'          => 'Acme',
            'website'       => $website,
            'display_order' => 10,
            'export_eshop'  => true,
            'archived'      => false,
        ];
    }

    public function testLegacyInvalidWebsiteDoesNotBlockRenamingManufacturer(): void
    {
        // Jádro regrese: uživatel mění jen jméno, `website` posílá beze změny zpátky.
        [$data, $err] = $this->validate(
            ['code' => 'ACME', 'name' => 'Acme Nove', 'website' => 'javascript:alert(1)'],
            $this->existing('javascript:alert(1)'),
        );

        self::assertNull($err, 'Nezměněná legacy adresa nesmí zablokovat uložení jména.');
        self::assertSame('Acme Nove', $data['name']);
        // Grandfathering NE — nebezpečná hodnota se z DB smaže, ne udrží naživu.
        self::assertNull($data['website']);
    }

    public function testLegacyInvalidWebsiteIsToleratedIgnoringSurroundingWhitespace(): void
    {
        [$data, $err] = $this->validate(
            ['code' => 'ACME', 'name' => 'Acme', 'website' => '  ftp://legacy.example.com  '],
            $this->existing('ftp://legacy.example.com'),
        );

        self::assertNull($err);
        self::assertNull($data['website']);
    }

    public function testChangedInvalidWebsiteIsStillRejected(): void
    {
        // Reálný překlep / útok — uživatel adresu upravil, tohle musí spadnout na 400.
        [, $err] = $this->validate(
            ['code' => 'ACME', 'name' => 'Acme', 'website' => 'javascript:alert(2)'],
            $this->existing('javascript:alert(1)'),
        );

        self::assertNotNull($err);
        self::assertSame(400, $err->getStatusCode());
    }

    public function testInvalidWebsiteOnCreateIsRejected(): void
    {
        // Bez `$existing` neexistuje co „zdědit" — create zůstává striktní.
        [, $err] = $this->validate(['code' => 'ACME', 'name' => 'Acme', 'website' => 'javascript:alert(1)']);

        self::assertNotNull($err);
        self::assertSame(400, $err->getStatusCode());
    }

    public function testInvalidWebsiteAgainstEmptyStoredValueIsRejected(): void
    {
        // Prázdná uložená hodnota se nesmí chovat jako „shoda" s čímkoliv neplatným.
        [, $err] = $this->validate(
            ['code' => 'ACME', 'name' => 'Acme', 'website' => 'javascript:alert(1)'],
            $this->existing(''),
        );

        self::assertNotNull($err);
        self::assertSame(400, $err->getStatusCode());

        [, $err2] = $this->validate(
            ['code' => 'ACME', 'name' => 'Acme', 'website' => 'javascript:alert(1)'],
            $this->existing(null),
        );

        self::assertNotNull($err2);
        self::assertSame(400, $err2->getStatusCode());
    }

    public function testValidWebsiteIsNormalizedAndStored(): void
    {
        [$data, $err] = $this->validate(
            ['code' => 'ACME', 'name' => 'Acme', 'website' => 'HTTPS://Example.com/Path'],
            $this->existing('javascript:alert(1)'),
        );

        self::assertNull($err);
        self::assertSame('https://Example.com/Path', $data['website']);
    }

    public function testOmittedWebsiteDropsLegacyInvalidStoredValue(): void
    {
        // Klient pole vůbec neposlal — uložená neplatná hodnota se stejně nesmí
        // protáhnout zpátky do odpovědi.
        [$data, $err] = $this->validate(
            ['code' => 'ACME', 'name' => 'Acme'],
            $this->existing('javascript:alert(1)'),
        );

        self::assertNull($err);
        self::assertNull($data['website']);
    }

    public function testNonStringWebsiteIsRejected(): void
    {
        [, $err] = $this->validate(
            ['code' => 'ACME', 'name' => 'Acme', 'website' => ['https://example.com']],
            $this->existing('https://example.com'),
        );

        self::assertNotNull($err);
        self::assertSame(400, $err->getStatusCode());
    }
}
