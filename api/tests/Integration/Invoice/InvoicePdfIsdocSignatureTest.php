<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SigningProfileRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Tests\Support\IsdocSignatureVerifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * ISDOC vložené do PDF vydané faktury nese vlastní XML podpis (ISDOC 6.0.2 kap. 5).
 *
 * POHODA při importu PDF ověřuje podpis VLOŽENÉHO ISDOC; PAdES podpis samotného
 * PDF za něj nestačí. Test jde přes skutečný render: podpisový profil pro výstup
 * „Vydaná faktura", vyrenderované PDF, vytažené `invoice.isdoc` a nezávislé
 * ověření jeho `ds:Signature`. Vše v transakci, která se na konci vrací.
 */
#[Group('integration')]
final class InvoicePdfIsdocSignatureTest extends TestCase
{
    private const SUPPLIER_ID = 1;

    private Connection $db;
    private InvoicePdfRenderer $renderer;
    private SigningProfileRepository $profiles;
    private SecretEncryption $secrets;
    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->renderer = $c->get(InvoicePdfRenderer::class);
            $this->profiles = $c->get(SigningProfileRepository::class);
            $this->secrets = $c->get(SecretEncryption::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $embed = $this->db->pdo()->query('SELECT embed_isdoc FROM supplier WHERE id = ' . self::SUPPLIER_ID)->fetchColumn();
        if ($embed === false || (int) $embed !== 1) {
            $this->markTestSkipped('Testovací dodavatel nemá zapnuté vkládání ISDOC.');
        }
        $this->db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        foreach ($this->cleanup as $path) {
            foreach ([$path, $path . '.new', $path . '.new.signed'] as $candidate) {
                if (is_file($candidate)) {
                    @unlink($candidate);
                }
            }
        }
    }

    public function testEmbeddedIsdocInSignedPdfCarriesValidXmlSignature(): void
    {
        $credential = IsdocSignatureVerifier::syntheticPfx('ISDOC PDF Test');
        $this->configureProfile($credential['pfx'], $credential['password'], 'fallback_unsigned');

        $pdf = $this->render();

        self::assertMatchesRegularExpression('#/SubFilter\s*/adbe\.pkcs7\.detached#', $pdf, 'PDF není podepsané.');
        $isdoc = (new PdfIsdocExtractor())->extract($pdf);
        self::assertIsString($isdoc, 'PDF nenese vložené ISDOC.');
        $certificate = IsdocSignatureVerifier::verify($isdoc);
        self::assertSame(
            openssl_x509_fingerprint($credential['cert'], 'sha256'),
            openssl_x509_fingerprint($certificate, 'sha256'),
        );
    }

    public function testFallbackPolicyEmbedsUnsignedIsdocWhenSigningFails(): void
    {
        $credential = IsdocSignatureVerifier::syntheticPfx();
        $this->configureProfile($credential['pfx'], 'wrong-password', 'fallback_unsigned');

        $isdoc = (new PdfIsdocExtractor())->extract($this->render());

        self::assertIsString($isdoc);
        self::assertStringNotContainsString('http://www.w3.org/2000/09/xmldsig#', $isdoc);
    }

    public function testFailClosedPolicyStopsRenderWhenIsdocSigningFails(): void
    {
        $credential = IsdocSignatureVerifier::syntheticPfx();
        $this->configureProfile($credential['pfx'], 'wrong-password', 'fail_closed');

        $this->expectExceptionMessage('Podpis ISDOC selhal.');
        $this->render();
    }

    private function configureProfile(string $pfx, string $password, string $failurePolicy): void
    {
        $certPath = tempnam(sys_get_temp_dir(), 'isdoc-sig-') . '.p12';
        file_put_contents($certPath, $pfx);
        $this->cleanup[] = $certPath;

        $profileId = $this->profiles->createProfile(
            self::SUPPLIER_ID,
            null,
            'ISDOC test',
            'isdoc-test-' . bin2hex(random_bytes(3)),
        );
        $this->profiles->upsertCredential(self::SUPPLIER_ID, $profileId, [
            'certificate_path' => $certPath,
            'passphrase_policy' => 'encrypted_store',
            'encrypted_passphrase' => $this->secrets->encrypt($password),
        ]);
        $this->profiles->upsertOutputSetting(self::SUPPLIER_ID, 'invoice', [
            'enabled' => true,
            'selection_source' => 'admin_profile_settings',
            'default_profile_id' => $profileId,
            'failure_policy' => $failurePolicy,
        ]);
    }

    private function render(): string
    {
        $varsymbol = 'SIG' . random_int(10000000, 99999999);
        $invoice = [
            'id' => 0,
            'invoice_type' => 'invoice',
            'status' => 'issued',
            'language' => 'cs',
            'currency' => 'CZK',
            'currency_id' => 0,
            'client_id' => 0,
            'supplier_id' => self::SUPPLIER_ID,
            'varsymbol' => $varsymbol,
            'issue_date' => '2026-07-01',
            'tax_date' => '2026-07-01',
            'due_date' => '2026-07-15',
            'paid_at' => null,
            'amount_to_pay' => 1210.0,
            'paid_total' => 0.0,
            'parent_invoice_id' => null,
            'payment_method' => 'bank_transfer',
            'prices_include_vat' => false,
            'reverse_charge' => false,
            'branding_profile_id' => null,
            'advance_paid_amount' => 0.0,
            'czk_recap' => null,
            'exchange_rate' => null,
            'pdf_path' => null,
            'pdf_generated_at' => null,
            'supplier_snapshot' => json_encode([
                'company_name' => 'Testovací dodavatel s.r.o.',
                'ic' => '01698401',
                'dic' => 'CZ01698401',
                'street' => 'Zkušební 1',
                'city' => 'Praha',
                'zip' => '11000',
                'country_iso2' => 'CZ',
                'is_vat_payer' => true,
            ], JSON_UNESCAPED_UNICODE),
            'client_snapshot' => json_encode([
                'company_name' => 'Testovací odběratel a.s.',
                'ic' => '27140130',
                'street' => 'Zkušební 2',
                'city' => 'Brno',
                'zip' => '60200',
                'country_iso2' => 'CZ',
            ], JSON_UNESCAPED_UNICODE),
            'bank_snapshot' => null,
            'items' => [[
                'description' => 'Konzultace',
                'quantity' => 1.0,
                'unit' => 'ks',
                'unit_price_without_vat' => 1000.0,
                'vat_rate_snapshot' => 21.0,
                'total_without_vat' => 1000.0,
                'total_vat' => 210.0,
                'total_with_vat' => 1210.0,
            ]],
            'totals' => ['without_vat' => 1000.0, 'vat' => 210.0, 'with_vat' => 1210.0, 'rounding' => 0.0],
            'vat_breakdown' => [['rate' => 21.0, 'base' => 1000.0, 'vat' => 210.0]],
        ];

        $path = $this->renderer->render(0, false, null, $invoice);
        $this->cleanup[] = $path;
        $pdf = file_get_contents($path);
        self::assertIsString($pdf);

        return $pdf;
    }
}
