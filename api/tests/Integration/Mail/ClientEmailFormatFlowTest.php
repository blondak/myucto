<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Mail;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Mail\PaymentThanksMailer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Předmět e-mailu a název přiloženého PDF podle klienta (myinvoice#277) od karty
 * klienta po proměnné e-mailu. `ClientRepository` má pevné seznamy sloupců
 * (INSERT, UPDATE, mergeWithStored) — bez nového sloupce v kterémkoli z nich by se
 * formát tiše neuložil nebo ho částečná úprava (MCP, integrace) smazala.
 */
#[Group('integration')]
final class ClientEmailFormatFlowTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ClientRepository $clients;
    private InvoiceRepository $invoices;
    private InvoiceEmailVarsBuilder $vars;
    private PaymentThanksMailer $thanks;
    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->clients = $c->get(ClientRepository::class);
            $this->invoices = $c->get(InvoiceRepository::class);
            $this->vars = $c->get(InvoiceEmailVarsBuilder::class);
            $this->thanks = $c->get(PaymentThanksMailer::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('clients', 'email_subject_format')) {
            self::fail('Migrace 1981 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET company_name = ? WHERE id = ?')
            ->execute(['Dodavatel Test s.r.o.', $this->supplierId]);
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals)
             VALUES (?, "CZK", "CZK", "Kč", "koruna česká", "Czech koruna", 2)'
        )->execute([$this->supplierId]);
        $this->currencyId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testFormatSeUlozIAPrezijeCastecnouUpravu(): void
    {
        $id = $this->client(['email_subject_format' => ' Klient_{DUZP_MM}_{DUZP_YYYY} ', 'email_attachment_name_format' => '']);

        $stored = $this->clients->find($id);
        self::assertSame('Klient_{DUZP_MM}_{DUZP_YYYY}', $stored['email_subject_format']);
        self::assertNull($stored['email_attachment_name_format'], 'Prázdný formát = výchozí (NULL).');

        // Částečná úprava bez klíčů formátu (MCP update_client, integrace) nesmí smazat ani jeden.
        $this->clients->update($id, ['email_attachment_name_format' => 'Dodavatel_{DUZP_MM}']);
        $this->clients->update($id, ['note' => 'jen poznámka']);
        $kept = $this->clients->find($id);
        self::assertSame('Klient_{DUZP_MM}_{DUZP_YYYY}', $kept['email_subject_format']);
        self::assertSame('Dodavatel_{DUZP_MM}', $kept['email_attachment_name_format']);

        $this->clients->update($id, ['email_subject_format' => null, 'email_attachment_name_format' => 'Dodavatel_{MM}']);
        $updated = $this->clients->find($id);
        self::assertNull($updated['email_subject_format']);
        self::assertSame('Dodavatel_{MM}', $updated['email_attachment_name_format']);
    }

    public function testEmailSFakturouPouzijeFormatyKlienta(): void
    {
        $invoice = $this->issuedInvoice($this->client([
            'email_subject_format' => 'Klient_{DUZP_MM}_{DUZP_YYYY}_{DODAVATEL}',
            'email_attachment_name_format' => 'Dodavatel_{DUZP_MM}_{DUZP_YYYY}',
        ]));

        $vars = $this->vars->build($invoice, false, 'cs');
        self::assertSame('Klient_09_2099_Dodavatel Test s.r.o.', $vars['subject']);
        self::assertSame('Klient_09_2099_Dodavatel Test s.r.o.', $vars['client_subject']);
        self::assertSame('[TEST] Klient_09_2099_Dodavatel Test s.r.o.', $this->vars->build($invoice, true, 'cs')['subject']);

        $attachment = $this->vars->pdfAttachment($invoice, '/data/storage/invoices/sup-1/2099-10/Faktura-2099277.pdf');
        self::assertSame('Dodavatel_09_2099.pdf', $attachment['name']);
        self::assertSame('/data/storage/invoices/sup-1/2099-10/Faktura-2099277.pdf', $attachment['path']);
        self::assertSame('application/pdf', $attachment['contentType']);
    }

    public function testBezFormatuZustavaVychoziPredmetINazev(): void
    {
        $invoice = $this->issuedInvoice($this->client([]));

        $vars = $this->vars->build($invoice, false, 'cs');
        self::assertNull($vars['client_subject']);
        self::assertStringStartsWith('Faktura ', $vars['subject']);
        self::assertSame(
            'Faktura-2099277.pdf',
            $this->vars->pdfAttachment($invoice, '/data/storage/invoices/sup-1/2099-10/Faktura-2099277.pdf')['name'],
        );
    }

    /**
     * Bez formátu u klienta musí být předmět i název přílohy bajtově stejné jako
     * před #277, pro všechny druhy dokladů, oba jazyky i testovací odeslání.
     */
    public function testBezFormatuJePredmetINazevPrilohyBajtoveJakoDriv(): void
    {
        $invoice = $this->issuedInvoice($this->client([]));
        $labels = [
            'invoice'          => ['cs' => 'Faktura', 'en' => 'Invoice'],
            'proforma'         => ['cs' => 'Zálohová faktura', 'en' => 'Proforma invoice'],
            'credit_note'      => ['cs' => 'Opravný daňový doklad', 'en' => 'Credit note'],
            'tax_document'     => ['cs' => 'Faktura', 'en' => 'Invoice'],
            'payment_calendar' => ['cs' => 'Faktura', 'en' => 'Invoice'],
        ];
        foreach ($labels as $type => $byLocale) {
            foreach ($byLocale as $locale => $label) {
                $doc = ['invoice_type' => $type, 'language' => $locale] + $invoice;
                foreach ([false, true] as $isTest) {
                    $vars = $this->vars->build($doc, $isTest, $locale);
                    self::assertNull($vars['client_subject'], "$type/$locale");
                    self::assertSame(
                        ($isTest ? '[TEST] ' : '') . "{$label} 2099277 — Dodavatel Test s.r.o.",
                        $vars['subject'],
                        "$type/$locale",
                    );
                }
                foreach (['Faktura-2099277.pdf', 'Proforma-2099277.pdf', 'Dobropis-2099277.pdf'] as $file) {
                    self::assertSame(
                        ['path' => "/data/storage/invoices/sup-1/2099-10/{$file}", 'name' => $file, 'contentType' => 'application/pdf'],
                        $this->vars->pdfAttachment($doc, "/data/storage/invoices/sup-1/2099-10/{$file}"),
                        "$type/$locale",
                    );
                }
            }
        }
    }

    public function testPodekovaniZaUhraduNepouzijePredmetFaktury(): void
    {
        $invoice = $this->issuedInvoice($this->client(['email_subject_format' => 'Klient_{DUZP_MM}']));

        $vars = (new \ReflectionMethod(PaymentThanksMailer::class, 'buildVars'))->invoke($this->thanks, $invoice, 'cs');

        self::assertNull($vars['client_subject'], 'Mailer by jinak předmětem klienta přepsal poděkování.');
        self::assertStringStartsWith('Děkujeme za úhradu', $vars['subject']);
    }

    /** @param array<string,mixed> $extra */
    private function client(array $extra): int
    {
        return $this->clients->create($extra + [
            'company_name' => 'Odběratel Test s.r.o.',
            'street' => 'Testovací 1',
            'city' => 'Praha',
            'zip' => '11000',
            'country_iso2' => 'CZ',
            'currency_default_id' => $this->currencyId,
            'language' => 'cs',
        ], $this->supplierId);
    }

    /** @return array<string,mixed> */
    private function issuedInvoice(int $clientId): array
    {
        $id = $this->invoices->createDraft([
            'supplier_id' => $this->supplierId,
            'invoice_type' => 'invoice',
            'client_id' => $clientId,
            'issue_date' => '2099-10-06',
            'tax_date' => '2099-09-30',
            'due_date' => '2099-10-20',
            'currency_id' => $this->currencyId,
            'language' => 'cs',
            'varsymbol' => '2099277',
        ], $this->userId);
        $this->db->pdo()->prepare(
            "UPDATE invoices SET status = 'issued', total_without_vat = 1000, total_vat = 210, total_with_vat = 1210 WHERE id = ?"
        )->execute([$id]);

        return $this->invoices->find($id) ?? self::fail('Faktura se nenačetla.');
    }
}
