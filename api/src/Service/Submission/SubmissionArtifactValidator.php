<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Service\Validation\XmlSchemaValidator;

/**
 * Lokální kontrola podkladu proti XSD před odesláním.
 *
 * ── Proč to musí být povinný krok ───────────────────────────────────────────
 * Datová schránka nevaliduje obsah příloh vůbec. Na rozdíl od EPO, které
 * vrátí seznam chyb hned, tady nedostaneme ani podací číslo, ani protokol.
 * Vadné podání se ozve až jako **výzva k odstranění vad podle § 74 DŘ** —
 * běžnou zprávou, po několika dnech, kdy už lhůta může být pryč.
 *
 * Tahle kontrola je tedy jediná náhrada za kontrolu, kterou nám druhá strana
 * neudělá. Není to komfort, je to jediné místo, kde se překlep v XML dá chytit
 * včas.
 *
 * `skipped` je poctivá odpověď: pro řadu agend (přehledy pojišťoven, mzdová
 * podání) schéma v `api/xsd/` nemáme. Tvrdit `passed` u nezkontrolovaného
 * souboru by bylo horší než přiznat, že se nekontrolovalo.
 */
final readonly class SubmissionArtifactValidator
{
    /**
     * Kód agendy → `form_code` v {@see XmlSchemaValidator}.
     *
     * Mapa je úmyslně neúplná: doplňuje se jen tam, kde schéma opravdu máme.
     */
    private const AGENDA_SCHEMAS = [
        'DPHDP3' => 'dphdp3',
        'DPHKH1' => 'dphkh1',
        'DPHSHV' => 'dphshv',
        'DPPDP9' => 'dppdp9',
        'DPPO' => 'dppdp9',
        'DPFDP5' => 'dpfdp5',
        'DPFDP7' => 'dpfdp7',
        'DPFO' => 'dpfdp5',
        'OSSEI1' => 'ossei1',
        'OSVC25' => 'osvc25',
    ];

    public function __construct(private XmlSchemaValidator $schemas) {}

    /**
     * @param array{filename:string,mime:string,bytes:string} $artifact
     * @return array{status:'passed'|'failed'|'skipped',errors:list<string>}
     */
    public function validateArtifact(string $agendaCode, array $artifact): array
    {
        $formCode = self::AGENDA_SCHEMAS[strtoupper($agendaCode)] ?? null;
        if ($formCode === null || !$this->schemas->hasSchema($formCode)) {
            return ['status' => 'skipped', 'errors' => []];
        }
        // Binární příloha (ZIP, PDF) se proti XSD validovat nedá — a předstírat
        // opak by znamenalo hlásit `failed` u něčeho, co je v pořádku.
        if (!str_contains($artifact['mime'], 'xml')) {
            return ['status' => 'skipped', 'errors' => []];
        }

        return $this->schemas->validate($artifact['bytes'], $formCode);
    }

    public function hasSchemaFor(string $agendaCode): bool
    {
        $formCode = self::AGENDA_SCHEMAS[strtoupper($agendaCode)] ?? null;
        return $formCode !== null && $this->schemas->hasSchema($formCode);
    }
}
