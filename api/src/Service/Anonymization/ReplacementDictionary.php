<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

/**
 * Slovník „originál → pseudonym" pro volné texty, JSON a XML.
 *
 * Strukturované sloupce (jména, IČO, účty…) se pseudonymizují jako první a každá
 * dvojice se sem zapíše. Texty dokladů, protokoly převodů, auditní payloady a
 * archivní XML pak dostanou TENTÝŽ pseudonym, takže párování a dohledání sedí
 * napříč tabulkami.
 *
 * Porovnává se po celých slovech (tokenech) bez ohledu na velikost písmen,
 * diakritiku a oddělovače: „Zkušební Obchod s.r.o.", „ZKUSEBNI OBCHOD SRO" i
 * „zkusebni obchod, s. r. o." jsou tentýž záznam — bankovní výpisy a XML podání
 * jména zapisují právě takhle. `str_replace` by nahradil i „Jan" uvnitř „Janov",
 * regulární výraz se statisíci alternativ by nešel zkompilovat.
 *
 * Náhrada přebírá podobu nalezeného textu: VELKÁ písmena zůstanou velká, text
 * bez diakritiky dostane pseudonym bez diakritiky. Jednoslovný záznam z písmen
 * (příjmení, jméno firmy) se nahradí jen ve slově začínajícím velkým písmenem —
 * příjmení „Nový" nesmí přepsat „nový rok".
 */
final class ReplacementDictionary
{
    /** @var array<string, array<int, array{tokens: list<string>, replacement: string, lower: bool}>> */
    private array $index = [];

    /** @var array<string, int> klíč fráze → pozice v kbelíku */
    private array $keys = [];

    /** @var array<string,bool> kbelíky, které je nutné před použitím seřadit */
    private array $dirty = [];

    private int $count = 0;

    public function add(string $original, string $replacement, int $minLength = 4): void
    {
        $original = trim($original);
        if ($original === '' || $original === $replacement || mb_strlen($original, 'UTF-8') < $minLength) {
            return;
        }
        $tokens = self::tokens($original);
        if ($tokens === [] || implode(' ', $tokens) === implode(' ', self::tokens($replacement))) {
            return;
        }
        $first = $tokens[0];
        $key = implode("\x1F", $tokens);
        if (isset($this->keys[$key])) {
            $this->index[$first][$this->keys[$key]]['replacement'] = $replacement;

            return;
        }
        // Záznam psaný malými písmeny (značka „firma123") se hledá i v malých písmenech.
        $this->index[$first][] = ['tokens' => $tokens, 'replacement' => $replacement, 'lower' => preg_match('/^\p{Ll}/u', $original) === 1];
        $this->keys[$key] = array_key_last($this->index[$first]);
        $this->dirty[$first] = true;
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }

    /** Pseudonym celé hodnoty (bez ohledu na velikost písmen a oddělovače), jinak null. */
    public function lookup(string $phrase): ?string
    {
        $tokens = self::tokens($phrase);
        $key = implode("\x1F", $tokens);
        if ($tokens === [] || !isset($this->keys[$key])) {
            return null;
        }

        return $this->index[$tokens[0]][$this->keys[$key]]['replacement'];
    }

    public function apply(string $text): string
    {
        if ($text === '' || $this->index === []) {
            return $text;
        }
        if (preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches, PREG_OFFSET_CAPTURE) === false || $matches[0] === []) {
            return $text;
        }
        $spans = $matches[0];
        $normalized = array_map(static fn (array $t): string => self::normalize($t[0]), $spans);
        $count = count($spans);
        $out = '';
        $cursor = 0;
        for ($i = 0; $i < $count; $i++) {
            if (!isset($this->index[$normalized[$i]])) {
                continue;
            }
            foreach ($this->bucket($normalized[$i]) as $entry) {
                $length = count($entry['tokens']);
                if ($i + $length > $count) {
                    continue;
                }
                for ($k = 1; $k < $length; $k++) {
                    if ($normalized[$i + $k] !== $entry['tokens'][$k]) {
                        continue 2;
                    }
                }
                $start = $spans[$i][1];
                $end = $spans[$i + $length - 1][1] + strlen($spans[$i + $length - 1][0]);
                $found = substr($text, $start, $end - $start);
                if ($length === 1 && !$entry['lower'] && preg_match('/^\p{L}/u', $found) === 1 && preg_match('/^\p{Lu}/u', $found) !== 1) {
                    continue;
                }
                // Náhrada končící tečkou („s.r.o.") pohltí tutéž tečku za nalezeným textem.
                if (preg_match('/[^\p{L}\p{N}]+$/u', $entry['replacement'], $tail) === 1 && substr($text, $end, strlen($tail[0])) === $tail[0]) {
                    $end += strlen($tail[0]);
                }
                $out .= substr($text, $cursor, $start - $cursor) . self::adapt($entry['replacement'], $found);
                $cursor = $end;
                $i += $length - 1;
                continue 2;
            }
        }

        return $cursor === 0 ? $text : $out . substr($text, $cursor);
    }

    /** @return list<array{tokens: list<string>, replacement: string, lower: bool}> */
    private function bucket(string $token): array
    {
        if (isset($this->dirty[$token])) {
            $bucket = $this->index[$token];
            uasort($bucket, static fn (array $a, array $b): int => count($b['tokens']) <=> count($a['tokens']));
            $this->index[$token] = $bucket;
            unset($this->dirty[$token]);
        }

        return array_values($this->index[$token]);
    }

    /** Podoba náhrady podle nalezeného textu: VELKÁ písmena a chybějící diakritika se přenesou. */
    private static function adapt(string $replacement, string $found): string
    {
        if (self::fold($found) === $found && self::fold($replacement) !== $replacement && preg_match('/[^\x00-\x7F]/', $found) !== 1) {
            $replacement = self::fold($replacement);
        }
        $letters = preg_replace('/[^\p{L}]/u', '', $found) ?? '';
        if (mb_strlen($letters, 'UTF-8') > 1 && mb_strtoupper($letters, 'UTF-8') === $letters) {
            $replacement = mb_strtoupper($replacement, 'UTF-8');
        }

        return $replacement;
    }

    /** @return list<string> */
    private static function tokens(string $value): array
    {
        if (preg_match_all('/[\p{L}\p{N}]+/u', $value, $m) === false) {
            return [];
        }

        return array_map(self::normalize(...), $m[0]);
    }

    private static function normalize(string $token): string
    {
        $normalized = mb_strtolower(self::fold($token), 'UTF-8');

        // Číselné tokeny zůstávají řetězcem i jako klíč pole (jinak by z nich PHP udělalo int).
        return ctype_digit($normalized) ? $normalized . '#' : $normalized;
    }

    /** Odstraní českou a slovenskou diakritiku (bez závislosti na iconv/intl). */
    public static function fold(string $value): string
    {
        return strtr($value, [
            'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
            'ĺ' => 'l', 'ľ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ř' => 'r',
            'ŕ' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y',
            'ž' => 'z', 'Á' => 'A', 'Ä' => 'A', 'Č' => 'C', 'Ď' => 'D', 'É' => 'E', 'Ě' => 'E',
            'Í' => 'I', 'Ĺ' => 'L', 'Ľ' => 'L', 'Ň' => 'N', 'Ó' => 'O', 'Ô' => 'O', 'Ö' => 'O',
            'Ř' => 'R', 'Ŕ' => 'R', 'Š' => 'S', 'Ť' => 'T', 'Ú' => 'U', 'Ů' => 'U', 'Ü' => 'U',
            'Ý' => 'Y', 'Ž' => 'Z',
        ]);
    }
}
