<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Výsledek ISDOC-first rozhodnutí {@see InvoiceExtractionRouter} (F7 §3.9).
 *
 * Readonly value object nesoucí:
 *   - `source`        — provenance label (`isdocx|isdoc_embedded|isdoc|ai`),
 *   - `isdocXml`      — vyřešený ISDOC XML (null u čistého `ai`),
 *   - `parsed`        — {@see IsdocParser::parse} výsledek, JEN když parse uspěl,
 *   - `useLlm`        — caller MUSÍ volat LLM právě tehdy, když je true,
 *   - `isdocPresent`  — byl fyzicky přítomen ISDOC (odlišuje „žádný ISDOC" od „ISDOC selhal parse"),
 *   - `parseError`    — chybová hláška u fyzicky přítomného, ale nevalidního ISDOC,
 *   - `isdocxPackage` — rozbalený ISDOCX balíček {isdoc,isdoc_name,pdf,pdf_name} u source=`isdocx`.
 *
 * Opravená sémantika (jádro fixu §3.9):
 *   - validní ISDOC parse ⇒ `useLlm=false` (LLM se NIKDY nevolá),
 *   - genuine parse failure fyzicky přítomného ISDOC ⇒ `useLlm=true` + `isdocPresent=true`
 *     (zaloguj a PŘESTO fallback na AI — nikdy nebrickovat import).
 */
final readonly class InvoiceExtractionDecision
{
    /**
     * @param 'isdocx'|'isdoc_embedded'|'isdoc'|'ai'          $source
     * @param array{supplier_ic:?string, invoices:list<array<string,mixed>>}|null $parsed
     * @param array{isdoc:string, isdoc_name:string, pdf:?string, pdf_name:?string}|null $isdocxPackage
     */
    public function __construct(
        public string $source,
        public ?string $isdocXml,
        public ?array $parsed,
        public bool $useLlm,
        public bool $isdocPresent,
        public ?string $parseError,
        public ?array $isdocxPackage,
    ) {}
}
