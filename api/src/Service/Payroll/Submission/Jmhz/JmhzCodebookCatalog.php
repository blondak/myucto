<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final class JmhzCodebookCatalog
{
    /** @var array<string, array<string, mixed>> */
    private array $codebooks = [];

    /** @param array{manifest_sha256:string,payload:array<string, mixed>} $manifest */
    public function __construct(array $manifest)
    {
        JmhzSpecPackageCatalog::validateManifest($manifest);
        $codebooks = $manifest['payload']['codebooks'] ?? null;
        if (!is_array($codebooks)) {
            throw new \InvalidArgumentException('Manifest JMHZ neobsahuje číselníky.');
        }
        foreach ($codebooks as $codebook) {
            if (!is_array($codebook) || !is_string($codebook['codebook_key'] ?? null)) {
                throw new \InvalidArgumentException('Manifest JMHZ obsahuje neplatný číselník.');
            }
            $this->codebooks[$codebook['codebook_key']] = $codebook;
        }
    }

    /** @return array<string, mixed> */
    public function requireValue(string $codebookKey, string $itemCode): array
    {
        $codebook = $this->codebooks[$codebookKey] ?? null;
        if ($codebook === null) {
            throw new JmhzCodebookUnavailableException(
                "Číselník JMHZ {$codebookKey} není v připnutém balíku dostupný.",
            );
        }
        if (($codebook['source_kind'] ?? null) !== 'embedded') {
            throw new JmhzCodebookUnavailableException(
                "Číselník JMHZ {$codebookKey} je pouze externí reference.",
            );
        }
        foreach ($codebook['entries'] ?? [] as $entry) {
            if (is_array($entry) && ($entry['item_code'] ?? null) === $itemCode) {
                return $entry;
            }
        }

        throw new JmhzCodebookValueException(
            "Hodnota {$itemCode} není v číselníku JMHZ {$codebookKey}.",
        );
    }

    /** @return list<array<string,mixed>> */
    public function entries(string $codebookKey): array
    {
        $codebook = $this->codebooks[$codebookKey] ?? null;
        if ($codebook === null || ($codebook['source_kind'] ?? null) !== 'embedded') {
            throw new JmhzCodebookUnavailableException(
                "Číselník JMHZ {$codebookKey} není vložený v připnutém balíku.",
            );
        }
        $entries = $codebook['entries'] ?? null;
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new JmhzCodebookUnavailableException(
                "Číselník JMHZ {$codebookKey} nemá platné položky.",
            );
        }
        return $entries;
    }
}
