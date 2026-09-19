<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Invoice;

use MyInvoice\Service\Invoice\DefaultInvoiceNote;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Výchozí text poznámky pod položkami (#79, migrace 1855).
 *
 * Pravidlo, které tyhle testy drží: text se vybírá podle JAZYKA dokladu, ne podle
 * měny, a vypnutý přepínač musí znamenat doslova dnešní chování — tedy prázdno,
 * i když jsou texty v nastavení vyplněné.
 */
#[CoversClass(DefaultInvoiceNote::class)]
final class DefaultInvoiceNoteTest extends TestCase
{
    private const CS = 'Zboží zůstává až do úplného uhrazení majetkem dodavatele.';
    private const EN = 'The goods remain the property of the supplier until paid in full.';

    /** @return array<string, mixed> */
    private static function supplier(bool $enabled = true): array
    {
        return [
            'default_note_below_items_enabled' => $enabled ? 1 : 0,
            'default_note_below_items_cs'      => self::CS,
            'default_note_below_items_en'      => self::EN,
        ];
    }

    public function testVypnutyPrepinacNevraciNicAniKdyzJsouTextyVyplnene(): void
    {
        self::assertSame('', DefaultInvoiceNote::forLanguage(self::supplier(false), 'cs'));
        self::assertSame('', DefaultInvoiceNote::forLanguage(self::supplier(false), 'en'));
    }

    public function testTextSeVybiraPodleJazykaDokladu(): void
    {
        self::assertSame(self::CS, DefaultInvoiceNote::forLanguage(self::supplier(), 'cs'));
        self::assertSame(self::EN, DefaultInvoiceNote::forLanguage(self::supplier(), 'en'));
    }

    public function testNeznamyJazykNeboChybejiciDodavatelNepredvyplniNic(): void
    {
        self::assertSame('', DefaultInvoiceNote::forLanguage(self::supplier(), 'de'));
        self::assertSame('', DefaultInvoiceNote::forLanguage(self::supplier(), null));
        self::assertSame('', DefaultInvoiceNote::forLanguage(null, 'cs'));
    }

    public function testPrazdnyNeboBilyTextSeChovaJakoNevyplneny(): void
    {
        $supplier = self::supplier();
        $supplier['default_note_below_items_en'] = "  \n\t ";
        self::assertSame('', DefaultInvoiceNote::forLanguage($supplier, 'en'));

        $supplier['default_note_below_items_en'] = null;
        self::assertSame('', DefaultInvoiceNote::forLanguage($supplier, 'en'));
    }

    public function testChybejiciSloupcePoNedobehleMigraciNepadaji(): void
    {
        self::assertSame('', DefaultInvoiceNote::forLanguage([], 'cs'));
    }

    public function testSupplierColumnsPokryvajiPrepinacIObaJazyky(): void
    {
        self::assertSame(
            [
                'default_note_below_items_enabled',
                'default_note_below_items_cs',
                'default_note_below_items_en',
            ],
            DefaultInvoiceNote::supplierColumns(),
        );
    }

    public function testChybejiciKlicSeDoplniPodleJazykaDokladu(): void
    {
        $data = InvoiceDefaults::withDefaultNote(['language' => 'en'], self::supplier());
        self::assertSame(self::EN, $data['note_below_items']);
    }

    /**
     * Vědomé vyprázdnění se nesmí „opravit" zpátky na výchozí text — editor i API
     * posílají klíč vždy, takže přítomnost klíče je jediný signál, že o poznámce
     * volající rozhodl sám.
     */
    public function testPritomnyKlicSeNikdyNeprepisuje(): void
    {
        foreach ([null, '', 'Vlastní text'] as $value) {
            $data = InvoiceDefaults::withDefaultNote(
                ['language' => 'cs', 'note_below_items' => $value],
                self::supplier(),
            );
            self::assertSame($value, $data['note_below_items']);
        }
    }

    public function testVypnutePredvyplnovaniKlicVubecNepridava(): void
    {
        $data = InvoiceDefaults::withDefaultNote(['language' => 'cs'], self::supplier(false));
        self::assertArrayNotHasKey('note_below_items', $data);
    }

    /**
     * Doplnění poznámky smí běžet jen při ZAKLÁDÁNÍ dokladu — tímtéž resolverem chodí
     * i PUT (UpdateInvoiceAction), kterému by jinak přeuložení doplnilo text do roky
     * staré faktury. Test hlídá, že je to opt-in: nový volající musí o doplnění říct
     * výslovně, jinak dostane dnešní chování.
     */
    public function testDoplneniPoznamkyJeOptInParametr(): void
    {
        $param = (new ReflectionMethod(InvoiceDefaults::class, 'resolve'))->getParameters()[1] ?? null;
        self::assertNotNull($param, 'resolve() musí mít parametr rozlišující zakládání dokladu.');
        self::assertSame('forNewInvoice', $param->getName());
        self::assertTrue($param->isDefaultValueAvailable());
        self::assertFalse($param->getDefaultValue());
    }
}
