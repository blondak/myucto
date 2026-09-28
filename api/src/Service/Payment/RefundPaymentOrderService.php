<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientBankAccountRepository;
use MyInvoice\Repository\PaymentOrderRepository;
use MyInvoice\Repository\RefundPaymentRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Invoice\RefundDocument;
use MyInvoice\Support\PaymentMethods;

/**
 * „Vrátit peníze" z detailu dokladu k vyplacení: platební příkaz s jedinou položkou.
 * Stažení a odeslání do banky pak jde přes běžné cesty platebních příkazů.
 *
 * Doklad musí být otevřený k vyplacení ({@see RefundDocument::isOpenRefund()}), v CZK,
 * nevyplácený hotově a dodavatel musí mít zapnuté `allow_refund_invoices`.
 * Jinak {@see \DomainException} s kódem `refund_disabled` / `not_refundable`.
 */
final class RefundPaymentOrderService
{
    public const REFUND_DISABLED = 'refund_disabled';
    public const NOT_REFUNDABLE = 'not_refundable';

    public function __construct(
        private readonly RefundPaymentRepository $refunds,
        private readonly PaymentOrderRepository $orders,
        private readonly PaymentOrderService $paymentOrders,
        private readonly ClientBankAccountRepository $clientAccounts,
        private readonly Connection $db,
    ) {}

    /** @return array<string,mixed>|null */
    public function prefill(int $invoiceId, int $supplierId): ?array
    {
        $invoice = $this->refunds->find($invoiceId, $supplierId);
        if ($invoice === null) {
            return null;
        }
        $this->assertRefundable($invoice, $supplierId);

        $payerAccounts = array_values(array_filter(
            $this->orders->payerAccounts($supplierId),
            static fn (array $a): bool => $a['is_active'] && strtoupper((string) $a['code']) === 'CZK',
        ));

        return [
            'invoice' => [
                'id'                  => $invoice['id'],
                'invoice_type'        => $invoice['invoice_type'],
                'varsymbol'           => $invoice['varsymbol'],
                'client_id'           => $invoice['client_id'],
                'client_company_name' => $invoice['client_company_name'],
                'currency'            => $invoice['currency'],
                'due_date'            => $invoice['due_date'],
                'payment_ordered_at'  => $invoice['payment_ordered_at'] ?? null,
            ],
            'amount'          => RefundDocument::refundAmount($invoice),
            'variable_symbol' => VariableSymbolNormalizer::forPayment((string) ($invoice['varsymbol'] ?? '')),
            'accounts'        => $this->paymentOrders->suggestedRefundAccounts((int) $invoice['client_id'], $supplierId),
            'payer_accounts'  => $payerAccounts,
        ];
    }

    /**
     * @param array<string,mixed> $input payer_currency_id, payment_date, account_number, bank_code,
     *                                   iban?, save_to_client?, note?
     * @return array{order_id:int, view:array<string,mixed>, clamped_date:bool, saved_account:?array<string,mixed>}|null
     * @throws \DomainException          doklad nejde vyplatit (409)
     * @throws \InvalidArgumentException neplatný vstup (422)
     */
    public function create(int $invoiceId, int $supplierId, array $input, ?int $userId): ?array
    {
        $invoice = $this->refunds->find($invoiceId, $supplierId);
        if ($invoice === null) {
            return null;
        }
        $this->assertRefundable($invoice, $supplierId);
        $payee = $this->paymentOrders->refundPayeeFromInput($input);

        $result = $this->paymentOrders->create($supplierId, [
            'refund_invoice_ids' => [$invoiceId],
            'refund_accounts'    => [$invoiceId => $payee],
            'payer_currency_id'  => (int) ($input['payer_currency_id'] ?? 0),
            'payment_date'       => (string) ($input['payment_date'] ?? ''),
            'note'               => $input['note'] ?? null,
        ], $userId);

        $saved = null;
        if (!empty($input['save_to_client'])) {
            $saved = $this->clientAccounts->addManual((int) $invoice['client_id'], $supplierId, $payee);
        }

        return [
            'order_id'      => $result['order_id'],
            'view'          => $result['view'],
            'clamped_date'  => $result['clamped_date'],
            'saved_account' => $saved,
        ];
    }

    /** @param array<string,mixed> $invoice */
    private function assertRefundable(array $invoice, int $supplierId): void
    {
        if (!RefundDocument::enabledForSupplier($this->db->pdo(), $supplierId)) {
            throw new \DomainException(self::REFUND_DISABLED);
        }
        if (!RefundDocument::isOpenRefund($invoice)
            || strtoupper((string) $invoice['currency']) !== 'CZK'
            || PaymentMethods::normalize($invoice['payment_method'] ?? null) === 'cash') {
            throw new \DomainException(self::NOT_REFUNDABLE);
        }
    }
}
