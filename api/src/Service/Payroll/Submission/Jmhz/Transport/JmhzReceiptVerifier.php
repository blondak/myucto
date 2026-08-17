<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Service\Payroll\Submission\PayrollReceiptVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceipt;

/**
 * Napojení protokolů JMHZ na platformu podání.
 *
 * Ověřený protokol je jediná cesta, jak se do `payroll_submissions` dostane
 * `remote_status` — proto se sem nesmí dostat nic, čemu není ověřený podpis.
 * Bez `JmhzProtocolSignatureVerifierInterface` verifier vyhodí výjimku a
 * `importReceipt()` musí být zavolané bez verifieru: uloží protokol jako
 * `verification_status='unverified'` a `remote_status` nechá prázdný. To je
 * správné chování, ne chyba — nepodepsaný protokol je jen příloha.
 */
final readonly class JmhzReceiptVerifier implements PayrollReceiptVerifierInterface
{
    /**
     * Kanály, jejichž protokol umíme ověřit.
     *
     * ── Proč tu ISDS přibylo ────────────────────────────────────────────────
     * Dřív tu stál jediný `vrep_apep` a cokoliv jiného skončilo
     * `jmhz_protocol_channel_unsupported`. To bylo správně jen do chvíle, než
     * se ISDS stal plnohodnotným kanálem podání JMHZ — od té chvíle to byl
     * rozpor: {@see \MyInvoice\Service\Submission\AgendaReceiptCapability::forChannel()}
     * u dvojice (`isds`, `JMHZ25`) vrací `ProcessingProtocol`, tedy aplikace
     * uživateli SLIBUJE, že protokol přečte, ale verifier ho odmítal otevřít.
     *
     * ── Proč je to bezpečné ─────────────────────────────────────────────────
     * Podepsaný protokol o zpracování je TENTÝŽ dokument bez ohledu na kanál.
     * Podávací a dotazovací protokol ČSSZ v1.47 to na straně 47 říká přímo:
     * „odpověď na podání ve formátu ‚holého‘ XML je zpráva ve formátu GovTalk,
     * stejně jako na podání ve formátu GovTalk". Kanál je jen doprava; důvěru
     * nese podpis ČSSZ, ne cesta, kterou dokument přišel.
     *
     * Podstatné je, že rozšíření NEOSLABUJE žádnou z ostatních bran. Protokol
     * pořád musí projít ověřením podpisu proti připnuté kotvě `DIS.CSSZ.2025`
     * a pořád musí sedět `CorrelationID` na konkrétní podání. Kanál sám o sobě
     * nikdy nebyl to, co protokol dělá důvěryhodným — kdyby byl, stačilo by
     * podvrhnout zprávu ve schránce.
     *
     * Ostatní kanály zůstávají odmítnuté: `manual_upload`, `pikr`,
     * `health_portal` ani `other` doložený tvar protokolu nemají a přijmout od
     * nich stav by znamenalo uzavřít povinnost podle dokumentu, o kterém nevíme,
     * co je zač.
     */
    private const CHANNELS = ['vrep_apep', 'isds'];

    /** @param array<string,int> $formPartIds GUID formuláře → id součásti podání */
    public function __construct(
        private ?JmhzProtocolSignatureVerifierInterface $signatures = null,
        private JmhzProtocolParser $parser = new JmhzProtocolParser(),
        private array $formPartIds = [],
        private int $packageCount = 1,
    ) {}

    /** @param array<array-key,int> $formPartIds klíče přicházejí zvenčí a ověřují se */
    public function withFormPartIds(array $formPartIds, int $packageCount = 1): self
    {
        $normalized = [];
        foreach ($formPartIds as $guid => $partId) {
            if (!is_string($guid) || $partId <= 0) {
                throw new JmhzTransportException(
                    'jmhz_protocol_form_mapping_invalid',
                    'Mapa GUID formulářů na součásti podání není platná.',
                );
            }
            $normalized[strtoupper($guid)] = $partId;
        }

        return new self(
            $this->signatures,
            $this->parser,
            $normalized,
            $packageCount,
        );
    }

    public function verify(
        string $bytes,
        string $channel,
        string $environment,
        ?string $expectedCorrelationReference,
    ): PayrollVerifiedReceipt {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new JmhzTransportException(
                'jmhz_protocol_channel_unsupported',
                'Protokol JMHZ umí ověřit jen kanál VREP/APEP nebo datovou schránku.',
            );
        }
        if ($this->signatures === null) {
            throw new JmhzTransportException(
                'jmhz_protocol_signature_verifier_missing',
                'Protokol ČSSZ nelze prohlásit za důvěryhodný bez ověření podpisu.',
            );
        }
        // Bez očekávaného CorrelationID nemá protokol na tohle podání žádnou
        // vazbu: parser variabilní symbol nikde nečte, takže by stačil platně
        // podepsaný protokol JAKÉHOKOLI zaměstnavatele, aby se z něj přenesl
        // stav „přijato". Podání odeslané přes VREP dostane CorrelationID už
        // při odeslání; jeho chybějící uložení je chyba, ne důvod protokol
        // přijmout.
        if ($expectedCorrelationReference === null) {
            throw new JmhzTransportException(
                'jmhz_protocol_correlation_unknown',
                'Podání nemá uložené CorrelationID, takže k němu nelze protokol'
                    . ' bezpečně přiřadit.',
            );
        }
        $xml = $this->signatures->verifiedProtocolXml($bytes, $environment);
        $report = $this->parser->parse(
            $xml,
            $this->packageCount,
            $expectedCorrelationReference,
        );
        // Protokol bez CorrelationID nelze k podání přiřadit. Propustit ho by
        // znamenalo vzít stav z dokumentu, o kterém nevíme, že k tomuhle podání
        // patří — a `remote_status` je jediný údaj, který v platformě rozhoduje
        // o přijetí.
        if ($report->correlationReference === null) {
            throw new JmhzTransportException(
                'jmhz_protocol_correlation_missing',
                'Ověřený protokol neuvádí CorrelationID, takže ho k podání'
                    . ' nelze přiřadit.',
            );
        }
        if (!hash_equals($expectedCorrelationReference, $report->correlationReference)) {
            throw new JmhzTransportException(
                'jmhz_protocol_correlation_mismatch',
                'Ověřený protokol patří jinému podání.',
            );
        }

        return new PayrollVerifiedReceipt(
            $report->status->payrollRemoteStatus(),
            $report->correlationReference,
            $this->partStatuses($report),
        );
    }

    /** @return array<int,string> */
    private function partStatuses(JmhzProtocolReport $report): array
    {
        if ($this->formPartIds === []) {
            return [];
        }
        $statuses = [];
        foreach ($report->formStatuses as $guid => $status) {
            $partId = $this->formPartIds[strtoupper($guid)] ?? null;
            if ($partId === null) {
                throw new JmhzTransportException(
                    'jmhz_protocol_form_unmapped',
                    'Protokol nese GUID formuláře, který k tomuhle podání nepatří.',
                );
            }
            $statuses[$partId] = $status->payrollRemoteStatus();
        }

        return $statuses;
    }
}
