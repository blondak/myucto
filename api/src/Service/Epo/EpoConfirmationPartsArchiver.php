<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

use MyInvoice\Service\Report\TaxSubmissionFilename;

/**
 * Uloží k podání čitelné části dodejky EPO.
 *
 * Sdílené oběma kanály: PŘÍMÉ podání (ZAREP) drží potvrzenku v ruce hned po odeslání,
 * u ASISTOVANÉHO ji účetní nahraje ručně přes „Nahrát výstupy z EPO". Vstup je v obou
 * případech týž bajtový balíček, takže by bylo nesmyslné, aby jedna cesta uměla vytáhnout
 * podací číslo, pečeť a echo podání a druhá nechala uživatele otevírat binární P7S.
 *
 * BEST-EFFORT ZÁMĚRNĚ: právně rozhodující doklad o přijetí je sama P7S a tu archivuje
 * volající dřív, než se sáhne sem. Kdyby selhání DOPROVODNÉHO souboru shodilo potvrzené
 * podání do „nejistého" stavu, vyrobili bychom paniku kvůli příloze. Neúspěch se proto
 * jen vrátí volajícímu, který ho zaznamená do auditu.
 */
final class EpoConfirmationPartsArchiver
{
    public function __construct(
        private readonly EpoConfirmationExtractor $extractor,
        private readonly TaxSubmissionDocumentService $documents,
    ) {}

    /**
     * @param array<string,mixed> $submission
     * @return array{stored:list<string>,failed:list<string>,receipt:array<string,mixed>}
     */
    public function archive(
        string $confirmationBytes,
        array $submission,
        int $supplierId,
        ?int $attemptId,
        ?int $userId,
        string $environment,
    ): array {
        try {
            $parts = $this->extractor->extract($confirmationBytes);
        } catch (\Throwable) {
            $parts = [];
        }

        // Přípona echa se řídí tím, co v něm SKUTEČNĚ je: ověřené je hexem kódované XML
        // u kontrolního hlášení, ale u DPH přiznání, souhrnného hlášení či DPPO může EPO
        // vrátit jinou obálku (base64, ZIP). Natvrdo `.xml` by u takového souboru lhalo.
        $echo = $parts['echo'] ?? null;
        $files = [
            // suffix                  => [artifact_kind, obsah]
            'confirmation.xml'         => ['confirmation_xml', $parts['confirmation_xml'] ?? null],
            'epo-echo.' . (is_array($echo) ? $echo['suffix'] : 'xml')
                                       => ['epo_echo', is_array($echo) ? $echo['bytes'] : null],
            'epo-seal.pem'             => ['confirmation_signer_cert', $parts['seal_certificate_pem'] ?? null],
            'signing-certificate.pem'  => ['submission_signer_cert', $parts['submission_certificate_pem'] ?? null],
        ];

        // Shrnutí dodejky (podací číslo, rozhodný čas, kontrolní součty, kdo podal a čí
        // pečetí je to potvrzené) visí na čitelném přepisu potvrzenky — detail podání ho
        // odtud čte, aby účetní nemusela otevírat XML. Heslo pro dotaz na stav v něm
        // ZÁMĚRNĚ není, viz EpoConfirmationExtractor::receipt().
        $receipt = is_array($parts['receipt'] ?? null) ? $parts['receipt'] : [];

        $stored = [];
        $failed = [];
        foreach ($files as $suffix => [$kind, $bytes]) {
            if (!is_string($bytes) || $bytes === '') {
                $failed[] = $kind;
                continue;
            }
            try {
                $this->documents->storeGeneratedArtifact(
                    $bytes,
                    TaxSubmissionFilename::forSnapshot(
                        $submission,
                        $suffix,
                        $attemptId,
                        new \DateTimeImmutable('now'),
                    ),
                    $kind,
                    $submission,
                    $supplierId,
                    $attemptId,
                    $userId,
                    'valid',
                    [
                        'derived_from' => 'confirmation_p7s',
                        'epo_environment' => $environment,
                        ...($kind === 'confirmation_xml' && $receipt !== [] ? ['receipt' => $receipt] : []),
                    ],
                );
                $stored[] = $kind;
            } catch (\Throwable) {
                $failed[] = $kind;
            }
        }

        return ['stored' => $stored, 'failed' => $failed, 'receipt' => $receipt];
    }
}
