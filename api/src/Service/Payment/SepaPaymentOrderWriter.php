<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment;

use MyInvoice\Service\Export\ExportFilename;

/**
 * Generátor **SEPA Credit Transfer** XML (ISO 20022 `pain.001.001.03`) — pro EUR
 * (obecně SEPA) platby dodavatelům, vedle tuzemského ABO/KPC (`AboPaymentOrderWriter`,
 * CZK-only). Ověřeno proti oficiálnímu schématu `api/xsd/pain.001.001.03.xsd`.
 *
 * Na rozdíl od ABO (číslo účtu + kód banky) SEPA scheme identifikuje účty výhradně
 * přes **IBAN** — BIC je od r. 2016 v rámci SEPA/EHP nepovinný (doplní se, jen je-li
 * znám); plátce i každý příjemce v dávce musí mít platný IBAN, jinak writer dávku/
 * položku defenzivně odmítne výjimkou (stejný vzor jako AboPaymentOrderWriter).
 *
 * Volba `pain.001.001.03` (ne novější `.09`/`.10`) — nejrozšířenější, univerzálně
 * přijímaná verze u českých bank (ČS, KB, ČSOB, Raiffeisenbank …) přes internetové
 * bankovnictví; novější verze (ISO 20022 CBPR+/pacs migrace) banky pro klientský
 * import příkazů zatím prakticky nevyžadují.
 */
final class SepaPaymentOrderWriter
{
    private const NS = 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03';

    public function __construct(private readonly IbanValidator $iban) {}

    /**
     * @param array{
     *     order_id?: int|string,
     *     initiator_name?: ?string,
     *     payer_name?: ?string,
     *     payer_iban: string,
     *     payer_bic?: ?string,
     *     payment_date: string|\DateTimeInterface,
     *     currency?: string,
     *     items: list<array{
     *         payee_name?: ?string, iban: ?string, bic?: ?string, amount: int|float,
     *         variable_symbol?: ?string, message?: ?string,
     *     }>
     * } $order
     *
     * @throws \InvalidArgumentException při prázdné dávce nebo chybějícím/neplatném IBAN plátce či příjemce
     */
    public function build(array $order): string
    {
        $items = $order['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new \InvalidArgumentException('Platební příkaz neobsahuje žádnou položku.');
        }

        $payerIban = $this->iban->normalize((string) ($order['payer_iban'] ?? ''));
        if ($payerIban === '' || !$this->iban->isValid($payerIban)) {
            throw new \InvalidArgumentException('Účet plátce nemá platný IBAN — SEPA export vyžaduje IBAN.');
        }
        $payerBic = $this->normalizedBic((string) ($order['payer_bic'] ?? ''));

        $execDate = $this->normalizeDate($order['payment_date']);
        $currency = strtoupper((string) ($order['currency'] ?? 'EUR'));
        $now = new \DateTimeImmutable();
        // Deterministický z order_id (BEZ timestampu) — stejná dávka musí mít při
        // opakovaném stažení STEJNÉ MsgId napříč re-exporty, jinak banka nemůže
        // uplatnit vlastní duplicate-detection (riziko dvojí platby při 2× uploadu).
        $msgId = $this->text('MYUCTO-' . (string) ($order['order_id'] ?? '1'), 35);

        $totalAmount = 0.0;
        foreach ($items as $it) {
            $totalAmount += round((float) ($it['amount'] ?? 0), 2);
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $document = $dom->createElementNS(self::NS, 'Document');
        $dom->appendChild($document);
        $cstmr = $dom->createElementNS(self::NS, 'CstmrCdtTrfInitn');
        $document->appendChild($cstmr);

        // ── GrpHdr (hlavička zprávy) ──
        $grpHdr = $dom->createElementNS(self::NS, 'GrpHdr');
        $cstmr->appendChild($grpHdr);
        $this->el($dom, $grpHdr, 'MsgId', $msgId);
        $this->el($dom, $grpHdr, 'CreDtTm', $now->format('Y-m-d\TH:i:s'));
        $this->el($dom, $grpHdr, 'NbOfTxs', (string) count($items));
        $this->el($dom, $grpHdr, 'CtrlSum', $this->fmtAmount($totalAmount));
        $initgPty = $dom->createElementNS(self::NS, 'InitgPty');
        $grpHdr->appendChild($initgPty);
        $this->el($dom, $initgPty, 'Nm', $this->text((string) ($order['initiator_name'] ?? $order['payer_name'] ?? ''), 140) ?: 'Platce');

        // ── PmtInf (platební instrukce dávky) ──
        $pmtInf = $dom->createElementNS(self::NS, 'PmtInf');
        $cstmr->appendChild($pmtInf);
        $this->el($dom, $pmtInf, 'PmtInfId', $msgId);
        $this->el($dom, $pmtInf, 'PmtMtd', 'TRF');
        $this->el($dom, $pmtInf, 'NbOfTxs', (string) count($items));
        $this->el($dom, $pmtInf, 'CtrlSum', $this->fmtAmount($totalAmount));

        $pmtTpInf = $dom->createElementNS(self::NS, 'PmtTpInf');
        $pmtInf->appendChild($pmtTpInf);
        $svcLvl = $dom->createElementNS(self::NS, 'SvcLvl');
        $pmtTpInf->appendChild($svcLvl);
        $this->el($dom, $svcLvl, 'Cd', 'SEPA');

        $this->el($dom, $pmtInf, 'ReqdExctnDt', $execDate->format('Y-m-d'));

        $dbtr = $dom->createElementNS(self::NS, 'Dbtr');
        $pmtInf->appendChild($dbtr);
        $this->el($dom, $dbtr, 'Nm', $this->text((string) ($order['payer_name'] ?? ''), 140) ?: 'Platce');

        $dbtrAcct = $dom->createElementNS(self::NS, 'DbtrAcct');
        $pmtInf->appendChild($dbtrAcct);
        $dbtrAcctId = $dom->createElementNS(self::NS, 'Id');
        $dbtrAcct->appendChild($dbtrAcctId);
        $this->el($dom, $dbtrAcctId, 'IBAN', $payerIban);

        // DbtrAgt je v pain.001.001.03 POVINNÝ element (na rozdíl od CdtrAgt níže,
        // který je minOccurs="0") — bez něj banka celou dávku odmítne jako
        // strukturálně vadnou. BIC uvnitř je nepovinný (SEPA/EHP od r. 2016), ale
        // element FinInstnId musí být vyplněný něčím → EPC guideline fallback
        // Othr/Id=NOTPROVIDED, když BIC není znám.
        $dbtrAgt = $dom->createElementNS(self::NS, 'DbtrAgt');
        $pmtInf->appendChild($dbtrAgt);
        $finInstnId = $dom->createElementNS(self::NS, 'FinInstnId');
        $dbtrAgt->appendChild($finInstnId);
        if ($payerBic !== '') {
            $this->el($dom, $finInstnId, 'BIC', $payerBic);
        } else {
            $othr = $dom->createElementNS(self::NS, 'Othr');
            $finInstnId->appendChild($othr);
            $this->el($dom, $othr, 'Id', 'NOTPROVIDED');
        }

        $this->el($dom, $pmtInf, 'ChrgBr', 'SLEV');

        foreach ($items as $i => $item) {
            $pmtInf->appendChild($this->buildTransaction($dom, $item, (int) $i, $currency));
        }

        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('Nepodařilo se serializovat SEPA XML.');
        }
        return $xml;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function buildTransaction(\DOMDocument $dom, array $item, int $index, string $currency): \DOMElement
    {
        $iban = $this->iban->normalize((string) ($item['iban'] ?? ''));
        if ($iban === '' || !$this->iban->isValid($iban)) {
            throw new \InvalidArgumentException(
                'Položka #' . ($index + 1) . ' nemá platný IBAN — do SEPA příkazu ji nelze zařadit.'
            );
        }
        $amount = round((float) ($item['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Položka #' . ($index + 1) . ' má nekladnou částku.');
        }

        $tx = $dom->createElementNS(self::NS, 'CdtTrfTxInf');

        $pmtId = $dom->createElementNS(self::NS, 'PmtId');
        $tx->appendChild($pmtId);
        $vs = (string) ($item['variable_symbol'] ?? '');
        $endToEnd = $this->text($vs !== '' ? $vs : ('PLATBA' . ($index + 1)), 35);
        $this->el($dom, $pmtId, 'EndToEndId', $endToEnd !== '' ? $endToEnd : 'NOTPROVIDED');

        $amt = $dom->createElementNS(self::NS, 'Amt');
        $tx->appendChild($amt);
        $instdAmt = $dom->createElementNS(self::NS, 'InstdAmt', $this->fmtAmount($amount));
        $instdAmt->setAttribute('Ccy', $currency);
        $amt->appendChild($instdAmt);

        $bic = $this->normalizedBic((string) ($item['bic'] ?? ''));
        if ($bic !== '') {
            $cdtrAgt = $dom->createElementNS(self::NS, 'CdtrAgt');
            $tx->appendChild($cdtrAgt);
            $finInstnId = $dom->createElementNS(self::NS, 'FinInstnId');
            $cdtrAgt->appendChild($finInstnId);
            $this->el($dom, $finInstnId, 'BIC', $bic);
        }

        $cdtr = $dom->createElementNS(self::NS, 'Cdtr');
        $tx->appendChild($cdtr);
        $this->el($dom, $cdtr, 'Nm', $this->text((string) ($item['payee_name'] ?? ''), 140) ?: 'Prijemce');

        $cdtrAcct = $dom->createElementNS(self::NS, 'CdtrAcct');
        $tx->appendChild($cdtrAcct);
        $cdtrAcctId = $dom->createElementNS(self::NS, 'Id');
        $cdtrAcct->appendChild($cdtrAcctId);
        $this->el($dom, $cdtrAcctId, 'IBAN', $iban);

        $message = $this->text((string) ($item['message'] ?? $vs), 140);
        if ($message !== '') {
            $rmtInf = $dom->createElementNS(self::NS, 'RmtInf');
            $tx->appendChild($rmtInf);
            $this->el($dom, $rmtInf, 'Ustrd', $message);
        }

        return $tx;
    }

    private function el(\DOMDocument $dom, \DOMElement $parent, string $name, string $value): \DOMElement
    {
        $el = $dom->createElementNS(self::NS, $name);
        $el->appendChild($dom->createTextNode($value));
        $parent->appendChild($el);
        return $el;
    }

    private function fmtAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * SEPA "Latin character set" — transliteruj diakritiku (stejně jako ABO writer),
     * sluč whitespace, ořízni na maximální délku pole (Max35Text/Max70Text/Max140Text).
     */
    private function text(string $s, int $maxLen): string
    {
        $s = ExportFilename::transliterate($s);
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        return mb_substr($s, 0, $maxLen);
    }

    private function normalizedBic(string $bic): string
    {
        $bic = strtoupper((string) preg_replace('/\s+/', '', $bic));
        return $bic !== '' && $this->iban->isValidBic($bic) ? $bic : '';
    }

    private function normalizeDate(string|\DateTimeInterface $date): \DateTimeImmutable
    {
        if ($date instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($date);
        }
        return new \DateTimeImmutable($date);
    }
}
