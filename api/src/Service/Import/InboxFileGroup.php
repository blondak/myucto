<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Jedna logická zásilka v inbox adresáři — soubory se shodným základem jména,
 * které dohromady popisují JEDEN doklad (typicky `faktura.isdoc` + `faktura.pdf`).
 *
 * Viz {@see InboxFileGrouper} pro pravidla seskupení a {@see PurchaseInvoiceInboxScanner},
 * který skupinu zpracovává jako celek (ne soubor po souboru).
 */
final readonly class InboxFileGroup
{
    /**
     * @param string|null   $data   Strojově čitelný originál (`.isdoc` / `.isdocx` / `.xml`),
     *                              null když v zásilce dorazilo jen PDF.
     * @param string|null   $pdf    Čitelné PDF, null když dorazila jen data.
     * @param list<string>  $extras Další sourozenci téhož základu jména (např. druhý datový
     *                              formát) — jen se přeskočí, ale patří do dedup kontroly.
     */
    public function __construct(
        public ?string $data,
        public ?string $pdf,
        public array $extras = [],
    ) {
        if ($data === null && $pdf === null) {
            throw new \InvalidArgumentException('InboxFileGroup musí mít alespoň data nebo pdf.');
        }
    }

    /** Soubor, podle kterého se skupina hlásí v reportu a progress výpisu. */
    public function primary(): string
    {
        return $this->data ?? (string) $this->pdf;
    }

    /** Skutečná dvojice „data + čitelný obraz" (kvůli ní celé seskupení vzniklo). */
    public function isPaired(): bool
    {
        return $this->data !== null && $this->pdf !== null;
    }

    /**
     * Všechny soubory skupiny v pořadí data → pdf → extras.
     *
     * @return list<string>
     */
    public function members(): array
    {
        $out = [];
        if ($this->data !== null) $out[] = $this->data;
        if ($this->pdf !== null)  $out[] = $this->pdf;
        return [...$out, ...$this->extras];
    }
}
