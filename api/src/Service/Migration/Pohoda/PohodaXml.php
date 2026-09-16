<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

/**
 * Proudové čtení exportu Pohody (`rsp:responsePack` s `lst:list…`).
 *
 * Soubory mají desítky MB (deník, faktury, banka), proto se nečtou do DOM celé: XMLReader
 * najde každý záznam (`accountingItem`, `invoice`, `bank`…) a teprve ten se rozvine na pole.
 * Kódování Windows-1250 z hlavičky převádí XMLReader sám, pole jsou v UTF-8.
 *
 * Tvar pole: klíče jsou lokální jména elementů bez jmenného prostoru (Pohoda používá
 * `inv:`, `typ:`, `bnk:`… a jména se mezi agendami neperou). List bez atributů je řetězec,
 * list s atributy je `['#' => text, '@atribut' => hodnota]`. Opakovaný element je seznam -
 * proto se opakovatelné věci (položky, úhrady, vazby) čtou přes {@see all()}.
 */
final class PohodaXml
{
    /**
     * @return \Generator<int,array<string,mixed>>
     */
    public static function records(string $file, string $tag): \Generator
    {
        $reader = self::open($file);
        if ($reader === null) {
            throw new PohodaException('export_unreadable', 'Soubor exportu ' . basename($file) . ' nejde otevřít jako XML.');
        }
        try {
            $ok = self::read($reader, $file);
            while ($ok) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === $tag) {
                    $node = $reader->expand();
                    if ($node instanceof \DOMElement) {
                        $value = self::toArray($node);
                        yield is_array($value) ? $value : ['#' => $value];
                    }
                    $ok = $reader->next();
                    continue;
                }
                $ok = self::read($reader, $file);
            }
        } finally {
            $reader->close();
        }
    }

    /** Počet záznamů v souboru bez rozvinutí na pole (přehled nahraného exportu). */
    public static function count(string $file, string $tag): int
    {
        $reader = self::open($file);
        if ($reader === null) {
            return 0;
        }
        $n = 0;
        try {
            $ok = self::read($reader, $file);
            while ($ok) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === $tag) {
                    $n++;
                    $ok = $reader->next();
                    continue;
                }
                $ok = self::read($reader, $file);
            }
        } finally {
            $reader->close();
        }
        return $n;
    }

    private static function open(string $file): ?\XMLReader
    {
        self::guard($file);
        $reader = new \XMLReader();
        return $reader->open($file, null, LIBXML_NONET) ? $reader : null;
    }

    /**
     * Další uzel dokumentu. DOCTYPE se odmítne jako uzel parseru, takže projde i ten schovaný
     * za dlouhým komentářem v prologu nebo v jiném kódování než ASCII.
     */
    private static function read(\XMLReader $reader, string $file): bool
    {
        $ok = $reader->read();
        if ($ok && $reader->nodeType === \XMLReader::DOC_TYPE) {
            throw new PohodaException('export_doctype', 'Soubor exportu ' . basename($file) . ' obsahuje DOCTYPE, což není povoleno.');
        }
        return $ok;
    }

    /**
     * Export přichází od uživatele: DOCTYPE (entity, billion laughs) Pohoda nepíše, takový
     * soubor se odmítne už podle začátku souboru; úplnou kontrolu dělá {@see self::read()}.
     */
    private static function guard(string $file): void
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            throw new PohodaException('export_unreadable', 'Soubor exportu ' . basename($file) . ' nejde přečíst.');
        }
        $head = (string) fread($handle, 8192);
        fclose($handle);
        if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false) {
            throw new PohodaException('export_doctype', 'Soubor exportu ' . basename($file) . ' obsahuje DOCTYPE, což není povoleno.');
        }
    }

    /**
     * Hlavička balíku: IČO a verze Pohody z kořene, stav odpovědi a text chyby z položky.
     *
     * @return array{ico:string,program:string,state:string,item_state:string,note:string,timestamp:string}
     */
    public static function packInfo(string $file): array
    {
        $out = ['ico' => '', 'program' => '', 'state' => '', 'item_state' => '', 'note' => '', 'timestamp' => ''];
        $reader = self::open($file);
        if ($reader === null) {
            return $out;
        }
        try {
            while (self::read($reader, $file)) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->localName === 'responsePack' || $reader->localName === 'mdbExport') {
                    $out['ico'] = (string) $reader->getAttribute('ico');
                    $out['program'] = (string) $reader->getAttribute('programVersion');
                    $out['state'] = (string) $reader->getAttribute('state');
                } elseif ($reader->localName === 'responsePackItem') {
                    $out['item_state'] = (string) $reader->getAttribute('state');
                    $out['note'] = (string) $reader->getAttribute('note');
                } elseif (str_starts_with($reader->localName, 'list') && $reader->getAttribute('dateTimeStamp') !== null) {
                    $out['timestamp'] = (string) $reader->getAttribute('dateTimeStamp');
                    break;
                } elseif ($out['item_state'] !== '' && $reader->depth > 2) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }
        return $out;
    }

    /** Hodnota na cestě `a/b/c`; u opakovaného elementu se bere první výskyt. */
    public static function get(mixed $node, string $path): mixed
    {
        foreach (explode('/', $path) as $key) {
            if (is_array($node) && array_is_list($node) && $node !== []) {
                $node = $node[0];
            }
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }
        return $node;
    }

    public static function text(mixed $node, string $path): string
    {
        $value = self::get($node, $path);
        if (is_array($value)) {
            $value = $value['#'] ?? null;
        }
        return is_string($value) ? trim($value) : '';
    }

    public static function num(mixed $node, string $path): float
    {
        $text = self::text($node, $path);
        return is_numeric($text) ? (float) $text : 0.0;
    }

    public static function attr(mixed $node, string $path, string $attribute): string
    {
        $value = self::get($node, $path);
        return is_array($value) && isset($value['@' . $attribute]) ? trim((string) $value['@' . $attribute]) : '';
    }

    /** Datum `RRRR-MM-DD` na cestě, jinak null. */
    public static function date(mixed $node, string $path): ?string
    {
        $text = self::text($node, $path);
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $text) === 1 ? substr($text, 0, 10) : null;
    }

    /**
     * Všechny výskyty elementu na cestě jako seznam (jediný výskyt se zabalí).
     *
     * @return list<mixed>
     */
    public static function all(mixed $node, string $path): array
    {
        $value = self::get($node, $path);
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        return is_array($value) && array_is_list($value) ? $value : [$value];
    }

    private static function toArray(\DOMElement $el): array|string
    {
        $out = [];
        foreach ($el->attributes ?? [] as $attr) {
            $out['@' . $attr->localName] = $attr->value;
        }
        $text = '';
        $hasChildren = false;
        $repeated = [];
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $hasChildren = true;
                $key = $child->localName;
                $value = self::toArray($child);
                if (!array_key_exists($key, $out)) {
                    $out[$key] = $value;
                } elseif (isset($repeated[$key])) {
                    $out[$key][] = $value;
                } else {
                    $out[$key] = [$out[$key], $value];
                    $repeated[$key] = true;
                }
            } elseif ($child instanceof \DOMText || $child instanceof \DOMCdataSection) {
                $text .= $child->data;
            }
        }
        if ($hasChildren) {
            return $out;
        }
        if ($out === []) {
            return $text;
        }
        $out['#'] = $text;
        return $out;
    }
}
