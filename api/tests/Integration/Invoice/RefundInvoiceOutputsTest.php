<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Vyúčtování s výsledkem k vyplacení v tiskových výstupech: PDF a e-mail místo výzvy
 * k úhradě hlásí „K vrácení" s kladnou částkou a bez platebních údajů, ISDOC projde XSD.
 */
#[Group('integration')]
final class RefundInvoiceOutputsTest extends StockTestCase
{
    private function refundInvoice(string $paymentMethod, string $type = 'invoice', string $language = 'cs'): array
    {
        $sid = $this->createSupplier(stockEnabled: false);
        $this->db->pdo()->prepare(
            "UPDATE currencies SET account_number = '1000000005', bank_code = '0100' WHERE id = ?"
        )->execute([$this->currencyIdFor($sid)]);
        $repo = $this->container->get(InvoiceRepository::class);
        $id = $repo->createDraft([
            'invoice_type' => $type,
            'client_id' => $this->client($sid),
            'currency_id' => $this->currencyIdFor($sid),
            'issue_date' => '2099-06-10', 'tax_date' => '2099-06-10', 'due_date' => '2099-06-24',
            'payment_method' => $paymentMethod, 'prices_include_vat' => false, 'language' => $language,
        ], $this->userId);
        $repo->replaceItems($id, [
            ['description' => 'Syntetické zboží', 'quantity' => 1, 'unit_price_without_vat' => 1000.00,
             'vat_rate_id' => $this->vatRateId, 'vat_classification_code' => '1'],
            ['description' => 'Syntetická vratka', 'quantity' => 1, 'unit_price_without_vat' => -1502.15,
             'vat_rate_id' => $this->vatRateId, 'vat_classification_code' => '1'],
        ]);
        $this->container->get(InvoiceCalculator::class)->recompute($id);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued', varsymbol = '2099001' WHERE id = ?")
            ->execute([$id]);

        return $repo->find($id);
    }

    public function testTransferRefundShowsAmountToRefundWithoutPaymentDetails(): void
    {
        $inv = $this->refundInvoice('bank_transfer');
        self::assertEqualsWithDelta(-607.60, (float) $inv['amount_to_pay'], 0.001);

        $html = $this->container->get(InvoicePdfRenderer::class)->renderHtml($inv);
        self::assertMatchesRegularExpression('~K vrácení</td>\s*<td[^>]*>607,60 CZK~u', $html);
        self::assertStringNotContainsString('K úhradě', $html);
        self::assertStringContainsString('Částku vám vrátíme převodem pod variabilním symbolem', $html);
        self::assertStringContainsString('2099001', $html);
        self::assertStringNotContainsString('alt="QR"', $html);
        self::assertStringNotContainsString('Bankovní spojení', $html);
        self::assertStringNotContainsString('1000000005', $html);
    }

    public function testCashRefundIsRoundedAndSaysPaidOutWhenPaid(): void
    {
        $inv = $this->refundInvoice('cash');
        self::assertEqualsWithDelta(-608.00, (float) $inv['amount_to_pay'], 0.001);
        $renderer = $this->container->get(InvoicePdfRenderer::class);

        $html = $renderer->renderHtml($inv);
        self::assertStringContainsString('Částku vám vyplatíme v hotovosti.', $html);
        self::assertStringContainsString('608,00', $html);

        $inv['status'] = 'paid';
        $inv['paid_at'] = '2099-06-10';
        $paid = $renderer->renderHtml($inv);
        self::assertStringContainsString('Vyplaceno v hotovosti.', $paid);
        self::assertStringNotContainsString('Neplaťte prosím znovu', $paid);
    }

    public function testEnglishPdfAndEmailUseRefundTexts(): void
    {
        $inv = $this->refundInvoice('bank_transfer', 'invoice', 'en');
        $html = $this->container->get(InvoicePdfRenderer::class)->renderHtml($inv);
        self::assertStringContainsString('Amount to be refunded', $html);
        self::assertStringContainsString('We will refund the amount by bank transfer under variable symbol', $html);

        $vars = $this->container->get(InvoiceEmailVarsBuilder::class)->build($inv, true, 'en');
        self::assertEqualsWithDelta(607.60, $vars['refund_amount'], 0.001);
        self::assertNull($vars['qr_data_uri']);
        foreach (['en', 'cs'] as $locale) {
            foreach (['html', 'txt'] as $format) {
                $body = $this->renderEmail("invoice_send.{$locale}.{$format}.twig", $vars);
                self::assertStringContainsString($locale === 'en' ? 'o be refunded' : 'K vrácení', $body);
                self::assertStringContainsString('607', $body);
                self::assertStringNotContainsString('-607', $body);
                self::assertStringContainsString($locale === 'en' ? 'under variable symbol' : 'pod variabilním symbolem', $body);
            }
        }
    }

    public function testCreditNoteAndPositiveInvoiceKeepTheirTexts(): void
    {
        $credit = $this->refundInvoice('bank_transfer', 'credit_note');
        $html = $this->container->get(InvoicePdfRenderer::class)->renderHtml($credit);
        self::assertStringNotContainsString('K vrácení', $html);
        self::assertNull($this->container->get(InvoiceEmailVarsBuilder::class)->build($credit, true, 'cs')['refund_amount']);

        self::assertNull(InvoicePdfRenderer::refundInvoiceAmount(['invoice_type' => 'invoice', 'amount_to_pay' => 608.0]));
        self::assertNull(InvoicePdfRenderer::refundInvoiceAmount(
            ['invoice_type' => 'invoice', 'amount_to_pay' => -100.0, 'parent_invoice_id' => 5],
        ));
    }

    public function testIsdocWithNegativePayableAmountValidates(): void
    {
        $inv = $this->refundInvoice('bank_transfer');
        $xml = $this->container->get(IsdocExporter::class)->buildXml($inv);
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml));
        self::assertTrue($dom->schemaValidate(dirname(__DIR__, 3) . '/xsd/isdoc-invoice-6.0.2.xsd'));
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('i', 'http://isdoc.cz/namespace/2013');
        self::assertSame('1', $xp->evaluate('string(//i:DocumentType)'));
        self::assertEqualsWithDelta(-607.60, (float) $xp->evaluate('string(//i:LegalMonetaryTotal/i:PayableAmount)'), 0.001);
    }

    /** @param array<string,mixed> $vars */
    private function renderEmail(string $template, array $vars): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates/email'), [
            'autoescape' => 'html', 'strict_variables' => false,
        ]);

        return $twig->render($template, $vars + ['accent' => '#3B2D83', 'accent_soft' => '#F1EEFA']);
    }
}
