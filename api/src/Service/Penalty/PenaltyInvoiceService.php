<?php

declare(strict_types=1);

namespace MyInvoice\Service\Penalty;

use DateTimeImmutable;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Invoice\InvoiceCalculator;

/**
 * Penalizace opožděných plateb — náhled výpočtu úroku z prodlení a založení
 * penalizační faktury z faktury po splatnosti.
 *
 * Penalizační faktura je běžná pohledávka (invoice_type='penalty') s jedním
 * řádkem = vypočtený úrok z prodlení. Úrok je MIMO předmět DPH (§ 2 ZDPH) →
 * nezahrnuje se do DPH evidence (VatLedgerService::fetchSales penalty vylučuje)
 * a účtuje se 311 / 644 (PostingService::buildFromInvoice, rule invoice.penalty.issued).
 */
final class PenaltyInvoiceService
{
    /** Typy zdrojových dokladů, ze kterých lze penalizovat (běžná pohledávka). */
    private const PENALIZABLE_TYPES = ['invoice'];

    /** Výchozí splatnost penalizační faktury (dny). */
    private const DEFAULT_DUE_DAYS = 14;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly PenaltyInterestCalculator $calculator,
        private readonly InvoiceCalculator $invoiceCalc,
        private readonly ExchangeRateApplier $rateApplier,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * Náhled výpočtu úroku z prodlení pro danou fakturu. Pokud už k faktuře
     * existuje dřívější (nestornovaná) penalizace, počítá se JEN za dny NEPOKRYTÉ
     * touto dřívější penalizací (§ 27.8 manuálu) — ochrana proti dvojímu
     * vyúčtování téhož období prodlení (audit Nález 5).
     *
     * @return array<string,mixed>
     * @throws \DomainException při nevalidním zdrojovém dokladu
     */
    public function preview(array $sourceInvoice, ?string $asOf = null, ?float $principalOverride = null): array
    {
        $this->assertPenalizable($sourceInvoice);

        $due  = new DateTimeImmutable((string) $sourceInvoice['due_date']);
        $explicitAsOf = $asOf !== null && $asOf !== '';
        $asOfDate = $explicitAsOf
            ? new DateTimeImmutable($asOf)
            : new DateTimeImmutable('today');

        $hasPrincipalOverride = $principalOverride !== null && $principalOverride > 0;
        $principal = $hasPrincipalOverride
            ? round($principalOverride, 2)
            : $this->penalizablePrincipal($sourceInvoice);
        $paymentTimeline = $this->repo->paymentTimeline(
            (int) $sourceInvoice['id'],
            (int) $sourceInvoice['supplier_id'],
        );
        $payments = $hasPrincipalOverride
            ? []
            : $paymentTimeline;

        if (!$explicitAsOf) {
            $fullyPaidOn = $this->fullyPaidOn($this->penalizablePrincipal($sourceInvoice), $paymentTimeline);
            if ($fullyPaidOn !== null) {
                $paymentDate = new DateTimeImmutable($fullyPaidOn);
                if ($paymentDate < $asOfDate) {
                    $asOfDate = $paymentDate;
                }
            }
        }

        $coveredThrough = $this->repo->lastPenaltyCoveredThrough((int) $sourceInvoice['id']);
        $accrualFrom = $coveredThrough !== null
            ? (new DateTimeImmutable($coveredThrough))->modify('+1 day')
            : null;

        $result = $this->calculator->compute($principal, $due, $asOfDate, $accrualFrom, $payments);
        $result['source_invoice_id'] = (int) $sourceInvoice['id'];
        $result['source_varsymbol']  = (string) ($sourceInvoice['varsymbol'] ?? '');
        $result['currency']          = (string) ($sourceInvoice['currency'] ?? 'CZK');
        $result['previously_covered_through'] = $coveredThrough;
        return $result;
    }

    /**
     * Založí penalizační fakturu (draft) z faktury po splatnosti. Vrací nově
     * vytvořenou fakturu (repo->find).
     *
     * @return array<string,mixed>
     * @throws \DomainException když faktura není po splatnosti / úrok je nulový
     */
    public function create(
        array $sourceInvoice,
        int $userId,
        ?string $asOf = null,
        ?float $principalOverride = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        $preview = $this->preview($sourceInvoice, $asOf, $principalOverride);

        if ($preview['total_interest'] <= 0.0) {
            if ($preview['previously_covered_through'] !== null) {
                throw new \DomainException(
                    'Toto období prodlení už bylo penalizováno (dřívější penalizace pokrývá do '
                        . $this->humanDate((string) $preview['previously_covered_through']) . ') — není co nově vyúčtovat.'
                );
            }
            throw new \DomainException('Faktura není po splatnosti (nebo úrok vychází nulový) — penalizační fakturu nelze vystavit.');
        }

        $today   = new DateTimeImmutable('today');
        $dueDate = $today->modify('+' . self::DEFAULT_DUE_DAYS . ' days');
        $varsymbol = (string) ($sourceInvoice['varsymbol'] ?? '');
        $currencyId = (int) $sourceInvoice['currency_id'];
        $language = (string) ($sourceInvoice['language'] ?? $sourceInvoice['client_language'] ?? 'cs');

        $periodFrom = (string) $preview['segments'][0]['from']; // guaranteed neprázdné (total_interest > 0 výše)
        $descr = $preview['previously_covered_through'] !== null
            ? sprintf(
                'Úrok z prodlení z faktury č. %s za období %s–%s (jistina %s %s, %d dní, dle NV č. 351/2013 Sb.) — navazuje na dřívější penalizaci do %s.',
                $varsymbol !== '' ? $varsymbol : ('#' . (int) $sourceInvoice['id']),
                $this->humanDate($periodFrom),
                $this->humanDate((string) $preview['as_of']),
                $this->fmt($preview['principal']),
                $preview['currency'],
                (int) $preview['total_days'],
                $this->humanDate((string) $preview['previously_covered_through']),
            )
            : sprintf(
                'Úrok z prodlení z faktury č. %s (jistina %s %s, %d dní po splatnosti dle NV č. 351/2013 Sb.)',
                $varsymbol !== '' ? $varsymbol : ('#' . (int) $sourceInvoice['id']),
                $this->fmt($preview['principal']),
                $preview['currency'],
                (int) $preview['total_days'],
            );

        $data = [
            'invoice_type'      => 'penalty',
            'parent_invoice_id' => (int) $sourceInvoice['id'],
            'client_id'         => (int) $sourceInvoice['client_id'],
            'issue_date'        => $today->format('Y-m-d'),
            'tax_date'          => $today->format('Y-m-d'),
            'due_date'          => $dueDate->format('Y-m-d'),
            'currency_id'       => $currencyId,
            'reverse_charge'    => 0,
            'prices_include_vat'=> 0,
            'language'          => $language,
            'payment_method'    => 'bank_transfer',
            'note_above_items'  => 'Penalizační faktura — zákonný úrok z prodlení dle nařízení vlády č. 351/2013 Sb. (mimo předmět DPH).',
            'items'             => [[
                'description'            => $descr,
                'quantity'               => 1,
                'unit'                   => 'ks',
                'unit_price_without_vat' => $preview['total_interest'],
                'vat_rate_id'            => $this->zeroVatRateId(),
            ]],
        ];

        $id = $this->repo->createDraft($data, $userId);
        $this->repo->replaceItems($id, $data['items']);
        $this->invoiceCalc->recompute($id);
        $this->rateApplier->applyToInvoice($id);
        // Zaznamená poslední den prodlení pokrytý touto penalizací — najde ji
        // navazující penalizace (viz preview/lastPenaltyCoveredThrough), aby se
        // stejné dny prodlení nevyúčtovaly dvakrát (audit Nález 5).
        $this->repo->setPenaltyCoveredThrough($id, (string) $preview['as_of']);

        $this->logger->log('invoice.penalty_created', $userId, 'invoice', $id, [
            'source_invoice_id'          => (int) $sourceInvoice['id'],
            'principal'                  => $preview['principal'],
            'total_days'                 => $preview['total_days'],
            'total_interest'             => $preview['total_interest'],
            'currency'                   => $preview['currency'],
            'previously_covered_through' => $preview['previously_covered_through'],
            'covered_through'            => $preview['as_of'],
        ], $ip, $userAgent);

        return $this->repo->find($id);
    }

    private function assertPenalizable(array $inv): void
    {
        if (!in_array((string) ($inv['invoice_type'] ?? ''), self::PENALIZABLE_TYPES, true)) {
            throw new \DomainException('Penalizovat lze jen běžnou vydanou fakturu (ne proformu/dobropis/storno).');
        }
        if (in_array((string) ($inv['status'] ?? ''), ['draft', 'cancelled'], true)) {
            throw new \DomainException('Penalizovat lze jen vystavenou fakturu.');
        }
    }

    private function penalizablePrincipal(array $inv): float
    {
        return max(0.0, round((float) ($inv['amount_to_pay'] ?? 0), 2));
    }

    /** @param list<array{paid_on:string, amount:float}> $payments */
    private function fullyPaidOn(float $principal, array $payments): ?string
    {
        $paid = 0.0;
        foreach ($payments as $payment) {
            $paid = round($paid + (float) $payment['amount'], 2);
            if ($paid >= $principal) {
                return (string) $payment['paid_on'];
            }
        }
        return null;
    }

    private function zeroVatRateId(): int
    {
        foreach ($this->repo->vatRateMap() as $id => $rate) {
            if ((float) $rate === 0.0) {
                return (int) $id;
            }
        }
        throw new \DomainException('V číselníku není 0% sazba DPH pro penalizační řádek.');
    }

    private function fmt(float $v): string
    {
        return number_format($v, 2, ',', ' ');
    }

    private function humanDate(string $ymd): string
    {
        return (new DateTimeImmutable($ymd))->format('d.m.Y');
    }
}
