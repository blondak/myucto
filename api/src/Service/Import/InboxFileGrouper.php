<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Seskupí soubory inboxu do zásilek podle ZÁKLADU JMÉNA v rámci jednoho adresáře.
 *
 * Proč: dodavatelé posílají fakturu jako dvojici `faktura.pdf` (čitelný doklad) +
 * `faktura.isdoc` / `faktura.xml` (data). Scanner je dřív bral jako dvě nezávislé
 * věci, takže PDF šlo na placenou AI extrakci i tehdy, když přesná data ležela
 * vedle — a při sebemenším rozdílu ve vyčtených údajích vznikl druhý koncept.
 *
 * Že to většinou dopadlo dobře, drželo jen abecední pořadí (`.isdoc` < `.pdf`)
 * plus unikátní klíče v DB. U `.xml` ale abeceda hraje OPAČNĚ (`.xml` > `.pdf`),
 * takže tam AI vyhrávala vždycky. Seskupení dělá z pořadí souborů nepodstatný detail.
 *
 * Pravidla:
 *   - Klíč skupiny = adresář + základ jména, case-insensitive (`Faktura.PDF` a
 *     `faktura.isdoc` patří k sobě i na case-sensitive FS).
 *   - Datový sourozenec se vybírá dle priority {@see DATA_PRIORITY}: `.isdocx` jde
 *     první, protože jako jediný nese čitelné PDF uvnitř sebe.
 *   - Kdyby v jednom koši byly DVĚ soubory téže přípony (na case-sensitive FS možné:
 *     `X.pdf` + `x.pdf`), nepáruje se nic — každý soubor dostane vlastní skupinu
 *     a chová se jako dřív. Hádat, který obraz patří ke kterým datům, do importu nepatří.
 */
final class InboxFileGrouper
{
    /** Přípony strojově čitelného originálu, od nejužitečnější. */
    private const DATA_PRIORITY = ['isdocx', 'isdoc', 'xml'];

    /**
     * @param  list<string>         $files Absolutní cesty (typicky už seřazené).
     * @return list<InboxFileGroup> Skupiny v pořadí prvního výskytu vstupu.
     */
    public static function group(array $files): array
    {
        /** @var array<string, list<string>> $buckets */
        $buckets = [];
        foreach ($files as $path) {
            $stem = pathinfo($path, PATHINFO_FILENAME);
            $key  = self::fold(dirname($path)) . '|' . self::fold($stem);
            $buckets[$key][] = $path;
        }

        $out = [];
        foreach ($buckets as $paths) {
            foreach (self::fromBucket($paths) as $group) {
                $out[] = $group;
            }
        }
        return $out;
    }

    /**
     * @param  list<string>         $paths
     * @return list<InboxFileGroup>
     */
    private static function fromBucket(array $paths): array
    {
        /** @var array<string, list<string>> $byExt */
        $byExt = [];
        foreach ($paths as $p) {
            $byExt[strtolower(pathinfo($p, PATHINFO_EXTENSION))][] = $p;
        }

        // Dvojznačnost (víc souborů téže přípony) → nepárovat, ať se nehádá.
        foreach ($byExt as $group) {
            if (count($group) > 1) {
                return self::standalone($paths);
            }
        }

        $data = null;
        foreach (self::DATA_PRIORITY as $ext) {
            if (isset($byExt[$ext])) {
                $data = $byExt[$ext][0];
                break;
            }
        }
        $pdf = $byExt['pdf'][0] ?? null;

        if ($data === null && $pdf === null) {
            return self::standalone($paths);
        }

        $extras = array_values(array_filter(
            $paths,
            static fn (string $p): bool => $p !== $data && $p !== $pdf,
        ));

        return [new InboxFileGroup($data, $pdf, $extras)];
    }

    /**
     * @param  list<string>         $paths
     * @return list<InboxFileGroup>
     */
    private static function standalone(array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            $isPdf = strtolower(pathinfo($p, PATHINFO_EXTENSION)) === 'pdf';
            $out[] = $isPdf ? new InboxFileGroup(null, $p) : new InboxFileGroup($p, null);
        }
        return $out;
    }

    /** Case-fold pro klíč koše — `mb_strtolower` kvůli diakritice v názvech. */
    private static function fold(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }
}
