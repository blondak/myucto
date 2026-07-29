<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * ISDOC-first rozhodovací služba (F7 §3.9, mustFix #7).
 *
 * Vlastní JEDINÉ místo s uspořádaným ISDOC-first pořadím detekce, dnes duplikovaným
 * v {@see AiPdfExtractor::extractAndCreate} A v {@see PurchaseInvoiceInboxScanner}.
 * Obě cesty (upload i inbox scanner) volají tento router → konec driftu.
 *
 * DECISION-ONLY: pouze ORCHESTRUJE existující nízkoúrovňové extraktory
 * ({@see IsdocxExtractor}, {@see PdfIsdocExtractor}, {@see IsdocParser}) — nereimplementuje
 * je. Nemapuje, nededuplikuje, nearchivuje — to zůstává na caller side.
 *
 * Pořadí (opravená sémantika):
 *   1. {@see IsdocxExtractor::isZip} → deterministický ISDOC z isdocx balíčku,
 *   2. embedded ISDOC v PDF/A-3 ({@see PdfIsdocExtractor::extract}),
 *   3. raw `.isdoc`/`.xml` (namespace sanity check; jen když je znám `$ext`),
 *   4. jinak → signál „použij LLM".
 *
 * Klíčová oprava (nahrazuje tichý try/catch fall-through, který mid-parse výjimkou
 * tiše utratil AI call, nebo naopak brickoval import):
 *   - VALIDNÍ ISDOC parse ⇒ `useLlm=false` — caller NIKDY nesmí volat LLM.
 *   - GENUINE parse failure fyzicky přítomného ISDOC ⇒ zaloguj chybu a `useLlm=true`
 *     (fallback na AI; nikdy nebrickovat import).
 */
final class InvoiceExtractionRouter
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly PdfIsdocExtractor $pdfIsdoc,
        private readonly IsdocParser $isdoc,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Rozhodne ISDOC-first pořadí nad surovými bajty souboru.
     *
     * @param string      $bytes surové bajty souboru (isdocx ZIP / PDF / raw ISDOC XML)
     * @param string|null $ext   přípona v lowercase (`pdf|isdoc|isdocx|xml`) — použije se
     *                           jen pro větev raw `.isdoc`/`.xml`; upload cesta ji nepředává.
     */
    public function decide(string $bytes, ?string $ext = null): InvoiceExtractionDecision
    {
        // 1) ISDOCX balíček (ZIP s vnitřním .isdoc + čitelným PDF). Magic check je levný;
        //    unwrap zapíše temp jen pro skutečný ZIP.
        if (IsdocxExtractor::isZip($bytes)) {
            $pkg = (new IsdocxExtractor())->unwrap($bytes);
            if ($pkg !== null) {
                return $this->fromXml($pkg['isdoc'], 'isdocx', $pkg);
            }
            // ZIP, který není použitelný ISDOCX balíček → žádný ISDOC → LLM.
            return $this->llmOnly();
        }

        // 2) Embedded ISDOC v PDF/A-3.
        if (str_starts_with($bytes, '%PDF')) {
            $xml = $this->pdfIsdoc->extract($bytes);
            if ($xml !== null) {
                return $this->fromXml($xml, 'isdoc_embedded', null);
            }
            return $this->llmOnly();
        }

        // 3) Raw `.isdoc` / `.xml` (jen když caller zná příponu — typicky inbox scanner).
        if ($ext === 'isdoc' || $ext === 'xml') {
            if (str_contains($bytes, 'isdoc.cz/namespace')) {
                return $this->fromXml($bytes, 'isdoc', null);
            }
        }

        // 4) Žádný ISDOC → LLM.
        return $this->llmOnly();
    }

    /**
     * Naparsuje vyřešený ISDOC XML a přeloží výsledek na rozhodnutí:
     *   - validní faktura ⇒ `useLlm=false`,
     *   - výjimka / prázdné invoices / `__error` ⇒ zaloguj a `useLlm=true` (fallback na AI).
     *
     * @param array{isdoc:string, isdoc_name:string, pdf:?string, pdf_name:?string}|null $isdocxPackage
     */
    private function fromXml(string $xml, string $source, ?array $isdocxPackage): InvoiceExtractionDecision
    {
        try {
            $parsed = $this->isdoc->parse($xml);
        } catch (\Throwable $e) {
            return $this->presentButFailed($xml, $source, $e->getMessage(), $isdocxPackage);
        }

        if (empty($parsed['invoices']) || isset($parsed['invoices'][0]['__error'])) {
            $err = $parsed['invoices'][0]['__error'] ?? 'ISDOC neobsahuje fakturu';
            return $this->presentButFailed($xml, $source, (string) $err, $isdocxPackage);
        }

        // Validní ISDOC parse — LLM se NIKDY nevolá.
        return new InvoiceExtractionDecision(
            source: $source,
            isdocXml: $xml,
            parsed: $parsed,
            useLlm: false,
            isdocPresent: true,
            parseError: null,
            isdocxPackage: $isdocxPackage,
        );
    }

    /**
     * Fyzicky přítomný ISDOC, který selhal parse → zaloguj a PŘESTO fallback na AI.
     *
     * @param array{isdoc:string, isdoc_name:string, pdf:?string, pdf_name:?string}|null $isdocxPackage
     */
    private function presentButFailed(string $xml, string $source, string $error, ?array $isdocxPackage): InvoiceExtractionDecision
    {
        $this->logger->warning('InvoiceExtractionRouter: ISDOC je přítomen, ale selhal parse — fallback na AI', [
            'source' => $source,
            'error'  => $error,
        ]);
        return new InvoiceExtractionDecision(
            source: $source,
            isdocXml: $xml,
            parsed: null,
            useLlm: true,
            isdocPresent: true,
            parseError: $error,
            isdocxPackage: $isdocxPackage,
        );
    }

    private function llmOnly(): InvoiceExtractionDecision
    {
        return new InvoiceExtractionDecision(
            source: 'ai',
            isdocXml: null,
            parsed: null,
            useLlm: true,
            isdocPresent: false,
            parseError: null,
            isdocxPackage: null,
        );
    }
}
