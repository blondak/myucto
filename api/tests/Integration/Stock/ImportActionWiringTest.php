<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Accounting\Codebooks\ChartOfAccountsImportAction;
use MyInvoice\Action\Eshop\ProductImportAction;
use MyInvoice\Action\Stock\VendorOfferImportAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Repository\StockItemVendorRepository;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;
use Slim\Psr7\UploadedFile;

/**
 * Regrese na tichý release blocker: `VendorOfferImportAction`
 * i `ProductImportAction` překrývaly `protected readonly Connection $db`
 * z `AbstractCodebookImportAction` vlastní `private` promovanou vlastností
 * a navíc předávaly do `parent::__construct()` dva ze tří argumentů. Obojí je
 * fatál UŽ PŘI NAČTENÍ TŘÍDY — `POST /api/stock/vendor-offers/import`
 * i `POST /api/eshop/products/import` skončily na 500 dřív, než se vůbec
 * dostaly k první řádce svého kódu.
 *
 * Proč to prošlo testy: `VendorOfferTest` volá `VendorOfferImportService`
 * PŘÍMO, akci nikdy nesestaví. Tenhle test proto jde záměrně přes kontejner
 * a přes `import()` s reálným uploadem — jen tak fatál při načtení třídy
 * někdo chytí.
 *
 * Druhá polovina testu hlídá bránu podvojného účetnictví: skladový a e-shopový
 * import ji mít NESMÍ (daňová evidence musí ceník i katalog naimportovat),
 * import účetních číselníků ji naopak mít MUSÍ. `requiresDoubleEntry()` je
 * default `true`, takže opomenutí je bezpečné; tenhle test hlídá, že se
 * opt-out nerozšíří tam, kam nepatří.
 */
#[Group('integration')]
final class ImportActionWiringTest extends StockTestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    // ── 1. Třídy se vůbec musí dát sestavit ──────────────────────────────────

    public function testImportActionsResolveFromContainer(): void
    {
        // Bez opravy tohle spadne na
        // „Access level to ...::$db must be protected ... or weaker" —
        // ne na výjimku, ale na fatál při načtení třídy.
        self::assertInstanceOf(
            VendorOfferImportAction::class,
            $this->container->get(VendorOfferImportAction::class),
        );
        self::assertInstanceOf(
            ProductImportAction::class,
            $this->container->get(ProductImportAction::class),
        );
    }

    // ── 2. Import ceníku dodavatele reálně proběhne ──────────────────────────

    public function testVendorOfferImportRunsEndToEndForTaxEvidenceCompany(): void
    {
        $sid = $this->createSupplier('tax_evidence');
        $this->item($sid, 'WIRE-VO');
        $this->client($sid, 'Dodavatel Wiring');

        $csv = "sku;dodavatel;kod_dodavatele;nakupni_cena;skladem_u_dodavatele;dostupnost;baleni\n"
            . "WIRE-VO;Dodavatel Wiring;W-1;77,50;12;skladem;3\n";

        $vendors = $this->container->get(StockItemVendorRepository::class);

        $dry = $this->importVendorOffers($sid, $csv, true);
        self::assertSame(200, $dry['status'], 'Daňová evidence nesmí na importu ceníku dostat 403.');
        self::assertSame(1, $dry['body']['created']);
        self::assertSame(0, $vendors->listOffers($sid)['total'], 'dry-run nesmí zapisovat');

        $real = $this->importVendorOffers($sid, $csv, false);
        self::assertSame(200, $real['status']);
        self::assertSame(1, $real['body']['created']);

        $offers = $vendors->listOffers($sid);
        self::assertSame(1, $offers['total'], 'Ostrý běh musí nabídku skutečně založit.');
        self::assertSame('77.50', $offers['items'][0]['purchase_price']);
        self::assertSame('W-1', $offers['items'][0]['vendor_sku']);
    }

    public function testVendorOfferImportStillRespectsStockEnabledGuard(): void
    {
        $sid = $this->createSupplier('tax_evidence', false);

        $csv = "sku;dodavatel;nakupni_cena\nWIRE-OFF;Kdokoli;10\n";
        $resp = $this->importVendorOffers($sid, $csv, true);

        self::assertSame(403, $resp['status'], 'Vypnutý skladový modul musí import pořád odmítnout.');
        self::assertSame('stock_disabled', $resp['body']['error']['code']);
    }

    // ── 3. Import katalogu zboží měl tentýž fatál ────────────────────────────

    public function testProductImportRunsEndToEndForTaxEvidenceCompany(): void
    {
        $sid = $this->createSupplier('tax_evidence');

        $csv = "sku;nazev;jednotka;cena\nWIRE-PRD;Zboží z importu;ks;249,00\n";

        $dry = $this->importProducts($sid, $csv, true);
        self::assertSame(200, $dry['status'], 'Daňová evidence nesmí na importu katalogu dostat 403.');
        self::assertSame(1, $dry['body']['created']);

        $items = $this->container->get(StockItemRepository::class);
        self::assertNull($items->findBySku($sid, 'WIRE-PRD'), 'dry-run nesmí zapisovat');

        $real = $this->importProducts($sid, $csv, false);
        self::assertSame(200, $real['status']);
        self::assertSame(1, $real['body']['created']);
        self::assertNotNull($items->findBySku($sid, 'WIRE-PRD'), 'Ostrý běh musí kartu skutečně založit.');
    }

    // ── 4. Účetní číselníky si bránu podvojného účetnictví MUSÍ nechat ───────

    public function testAccountingCodebookImportKeepsDoubleEntryGate(): void
    {
        $sid = $this->createSupplier('tax_evidence');

        /** @var ChartOfAccountsImportAction $action */
        $action = $this->container->get(ChartOfAccountsImportAction::class);
        $resp = $this->callImport(
            $action,
            '/api/accounting/accounts/import',
            $sid,
            "cislo;nazev\n518000;Ostatní služby\n",
            'osnova.csv',
            true,
        );

        self::assertSame(403, $resp['status'], 'Osnova je účetní číselník — daňová evidence ji importovat nesmí.');
        self::assertSame('wrong_accounting_mode', $resp['body']['error']['code']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{status:int, body:array<string,mixed>} */
    private function importVendorOffers(int $supplierId, string $csv, bool $dryRun): array
    {
        return $this->callImport(
            $this->container->get(VendorOfferImportAction::class),
            '/api/stock/vendor-offers/import',
            $supplierId,
            $csv,
            'cenik.csv',
            $dryRun,
        );
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function importProducts(int $supplierId, string $csv, bool $dryRun): array
    {
        return $this->callImport(
            $this->container->get(ProductImportAction::class),
            '/api/eshop/products/import',
            $supplierId,
            $csv,
            'zbozi.csv',
            $dryRun,
        );
    }

    /**
     * @param object{import: callable} $action
     * @return array{status:int, body:array<string,mixed>}
     */
    private function callImport(
        object $action,
        string $path,
        int $supplierId,
        string $csv,
        string $filename,
        bool $dryRun,
    ): array {
        $tmp = tempnam(sys_get_temp_dir(), 'impwire_');
        file_put_contents($tmp, $csv);
        $this->tmpFiles[] = $tmp;

        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withUploadedFiles(['file' => new UploadedFile(
                $tmp,
                $filename,
                'text/csv',
                strlen($csv),
                UPLOAD_ERR_OK,
            )])
            ->withParsedBody(['dry_run' => $dryRun ? '1' : '0']);

        /** @var ResponseInterface $resp */
        $resp = $action->import($req, new Psr7Response());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);

        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
