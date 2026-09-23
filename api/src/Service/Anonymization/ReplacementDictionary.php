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
 * Nahrazuje se po celých slovech: text se rozloží na tokeny (souvislé písmena a
 * číslice) a u tokenu, kterým nějaká fráze začíná, se zkusí fráze od nejdelší.
 * `str_replace` by nahradil i „Jan" uvnitř „Janov", regulární výraz se
 * statisíci alternativ by nešel zkompilovat.
 *
 * Každý záznam se ukládá i ve variantách VELKÝMI PÍSMENY a bez diakritiky —
 * bankovní výpisy a XML podání jména zapisují právě takhle.
 */
final class ReplacementDictionary
{
    /** @var array<string, array<string,string>> první token → fráze → náhrada */
    private array $index = [];

    /** @var array<string,bool> kbelíky, které je nutné před použitím seřadit */
    private array $dirty = [];

    private int $count = 0;

    public function add(string $original, string $replacement, int $minLength = 4): void
    {
        $original = trim($original);
        if ($original === '' || $original === $replacement || mb_strlen($original, 'UTF-8') < $minLength) {
            return;
        }
        $variants = [
            $original => $replacement,
            mb_strtoupper($original, 'UTF-8') => mb_strtoupper($replacement, 'UTF-8'),
            self::fold($original) => self::fold($replacement),
            mb_strtoupper(self::fold($original), 'UTF-8') => mb_strtoupper(self::fold($replacement), 'UTF-8'),
        ];
        foreach ($variants as $phrase => $target) {
            $phrase = (string) $phrase;
            if (preg_match('/[\p{L}\p{N}]+/u', $phrase, $m) !== 1) {
                continue;
            }
            $first = $m[0];
            if (!isset($this->index[$first][$phrase])) {
                $this->count++;
            }
            $this->index[$first][$phrase] = $target;
            $this->dirty[$first] = true;
        }
    }

    public function count(): int
    {
        return $this->count;
    }

    public function lookup(string $phrase): ?string
    {
        $phrase = trim($phrase);
        if (preg_match('/[\p{L}\p{N}]+/u', $phrase, $m) !== 1) {
            return null;
        }

        return $this->index[$m[0]][$phrase] ?? null;
    }

    public function apply(string $text): string
    {
        if ($text === '' || $this->index === []) {
            return $text;
        }
        if (preg_match_all('/[\p{L}\p{N}]+/u', $text, $tokens, PREG_OFFSET_CAPTURE) === false) {
            return $text;
        }
        $out = '';
        $cursor = 0;
        foreach ($tokens[0] as [$token, $offset]) {
            if ($offset < $cursor || !isset($this->index[$token])) {
                continue;
            }
            $bucket = $this->bucket($token);
            foreach ($bucket as $phrase => $replacement) {
                // Číselné klíče (IČO, účty) převádí PHP na int — zpět na řetězec.
                $phrase = (string) $phrase;
                $length = strlen($phrase);
                if (substr_compare($text, $phrase, $offset, $length) !== 0) {
                    continue;
                }
                $next = substr($text, $offset + $length, 4);
                if ($next !== '' && preg_match('/^[\p{L}\p{N}]/u', $next) === 1) {
                    continue;
                }
                $out .= substr($text, $cursor, $offset - $cursor) . $replacement;
                $cursor = $offset + $length;
                break;
            }
        }

        return $cursor === 0 ? $text : $out . substr($text, $cursor);
    }

    /** @return array<string,string> */
    private function bucket(string $token): array
    {
        if (isset($this->dirty[$token])) {
            uksort($this->index[$token], static fn (int|string $a, int|string $b): int => strlen((string) $b) <=> strlen((string) $a));
            unset($this->dirty[$token]);
        }

        return $this->index[$token];
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
