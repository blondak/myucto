<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

/**
 * Registrace ČSSZ (REGZEC25, PREZEC26), které PREMIER odeslal a ČSSZ PŘIJALA, přečtené ze zálohy.
 * Nic nezapisuje.
 *
 * PREMIER si každé odeslané podání ukládá doslova do tabulky `MZ_VREP`: tělo podání do memo
 * pole `POZNAMKA` (Windows-1250, před `<?xml` bývá zbytek po BOM) a odpověď příjemce do
 * `POZNAMKA2`. Odpověď nese příznak `<accepted>` celého podání a u každé věty (`employee@sqnr`)
 * položku `ProcessingResult/Details/Item` s výsledkem `OK` nebo `ERROR`. Podání, které ČSSZ
 * odmítla celé (`accepted` False), a věty s výsledkem `ERROR` v přijatém souboru se nepřebírají:
 * ČSSZ je neeviduje, takže by je převod nesměl vydávat za doložené.
 *
 * Z každé přijaté věty vznikne samostatný soubor REGZEC / PREZEC s touto jedinou větou. Import
 * registrací odmítá soubor s jedinou vadnou větou celý a věty pak plánuje a zapisuje po jedné
 * nad aktuálním stavem evidence, což je přesně to, co se při ručním nahrání souborů za sebou
 * děje. Věty jsou seřazené podle času odeslání.
 */
final class PremierPayrollSubmissions
{
    /** Typy podání, které umí produktový import registrací. */
    public const TYPES = ['REGZEC25', 'PREZEC26'];

    /**
     * @param list<array{name:string,content:string,type:string,vrep_id:string,key:string,sqnr:int,sent_at:string,date:?string}> $sentences
     * @param array{files:int,files_rejected:int,files_unreadable:int,sentences_rejected:int} $stats
     */
    private function __construct(
        public readonly array $sentences,
        public readonly array $stats,
    ) {}

    public static function fromBackup(PremierBackup $backup): self
    {
        $stats = ['files' => 0, 'files_rejected' => 0, 'files_unreadable' => 0, 'sentences_rejected' => 0];
        $rows = [];
        foreach ($backup->rows('MZ_VREP') as $position => $row) {
            if (in_array(trim((string) ($row['TYP_ZPRAVY'] ?? '')), self::TYPES, true)) {
                $rows[] = ['position' => $position, 'row' => $row];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [(string) ($a['row']['DAT_ZPRAVY'] ?? ''), $a['position']] <=> [(string) ($b['row']['DAT_ZPRAVY'] ?? ''), $b['position']]);

        $sentences = [];
        foreach ($rows as ['row' => $row]) {
            $stats['files']++;
            $response = (string) ($row['POZNAMKA2'] ?? '');
            if (preg_match('/<accepted>\s*True\s*<\/accepted>/i', $response) !== 1) {
                $stats['files_rejected']++;
                continue;
            }
            $results = self::sentenceResults($response);
            $document = self::document((string) ($row['POZNAMKA'] ?? ''));
            if ($document === null) {
                $stats['files_unreadable']++;
                continue;
            }
            $type = trim((string) $row['TYP_ZPRAVY']);
            $fullId = strtolower((string) preg_replace('/[^0-9A-Za-z-]/', '', (string) ($row['ID'] ?? '')));
            $id = substr(str_replace('-', '', $fullId), 0, 8);
            $employees = self::employees($document);
            foreach ($employees as $index => $employee) {
                $sqnr = (int) ($employee->getAttribute('sqnr') !== '' ? $employee->getAttribute('sqnr') : $index + 1);
                // Odpověď bez položek po větách (starší tvar) přijímá celé podání; s položkami platí jen věta s výsledkem jiným než ERROR.
                if ($results !== [] && (!isset($results[$sqnr]) || $results[$sqnr] === 'ERROR')) {
                    $stats['sentences_rejected']++;
                    continue;
                }
                $content = self::single($document, $index);
                if ($content === null) {
                    $stats['files_unreadable']++;
                    continue;
                }
                $date = $employee->getAttribute('dat');
                $sentences[] = [
                    'name' => "premier-{$type}-{$id}-{$sqnr}.xml",
                    'content' => $content,
                    'type' => $type,
                    'vrep_id' => $id,
                    'key' => $fullId . ':' . $sqnr,
                    'sqnr' => $sqnr,
                    'sent_at' => (string) ($row['DAT_ZPRAVY'] ?? ''),
                    'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1 ? $date : null,
                ];
            }
        }
        return new self($sentences, $stats);
    }

    /**
     * Výsledky po větách z odpovědi ČSSZ: `sqnr` => `OK` | `ERROR` | jiný. Souhrnná položka
     * bez `sqnr` se vynechá.
     *
     * @return array<int,string>
     */
    private static function sentenceResults(string $response): array
    {
        $decoded = html_entity_decode($response, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $results = [];
        if (preg_match_all('/<Item\b([^>]*)>/i', $decoded, $items) > 0) {
            foreach ($items[1] as $attributes) {
                if (preg_match('/\bsqnr="(\d+)"/', $attributes, $sqnr) === 1 && preg_match('/\bresult="([^"]*)"/', $attributes, $result) === 1) {
                    $results[(int) $sqnr[1]] = strtoupper(trim($result[1]));
                }
            }
        }
        return $results;
    }

    /** Tělo podání jako DOM; `null`, když to není dobře utvořené XML REGZEC / PREZEC. */
    private static function document(string $body): ?\DOMDocument
    {
        // Před deklarací zbývá po BOM otazník (PREMIER ho při uložení převede), deklarované kódování
        // neplatí: tělo už je převedené do UTF-8.
        $xml = (string) preg_replace('/^[^<]*(?:<\?xml[^>]*\?>)?/', '', $body);
        if (trim($xml) === '' || preg_match('/<!(DOCTYPE|ENTITY)/i', $xml) === 1) {
            return null;
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML('<?xml version="1.0" encoding="UTF-8"?>' . $xml, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        return $loaded && $root !== null && in_array($root->localName, ['REGZEC', 'PREZEC'], true) ? $document : null;
    }

    /** @return list<\DOMElement> */
    private static function employees(\DOMDocument $document): array
    {
        $out = [];
        foreach ($document->getElementsByTagNameNS('*', 'employee') as $employee) {
            $out[] = $employee;
        }
        return $out;
    }

    /** Soubor s jedinou větou `$index` (pořadí v původním souboru). */
    private static function single(\DOMDocument $document, int $index): ?string
    {
        $copy = new \DOMDocument();
        $copy->preserveWhiteSpace = false;
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$copy->loadXML((string) $document->saveXML(), LIBXML_NONET | LIBXML_NOCDATA)) {
                return null;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $employees = self::employees($copy);
        foreach ($employees as $i => $employee) {
            if ($i !== $index) {
                $employee->parentNode?->removeChild($employee);
            }
        }
        $out = $copy->saveXML();
        return $out === false ? null : $out;
    }
}
