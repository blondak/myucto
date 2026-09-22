<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

/** ObsahBck.txt: tři řádky hlavičky, poté index|popisek|identifikátor agendy. */
final class StereoNxManifest
{
    /** @return list<array{index:int,prefix:string,label:string,source_key:string}> */
    public static function parse(string $bytes): array
    {
        if (strlen($bytes) > 1048576 || str_contains($bytes, "\0")) {
            throw new StereoNxException('manifest_invalid', 'Neplatný seznam firem v záloze Stereo NX.');
        }
        $text = mb_check_encoding($bytes, 'UTF-8') ? $bytes : iconv('Windows-1250', 'UTF-8', $bytes);
        if ($text === false) {
            throw new StereoNxException('manifest_encoding', 'Nelze přečíst kódování seznamu firem.');
        }
        $lines = preg_split('/\r\n|\n|\r/', preg_replace('/^\xEF\xBB\xBF/', '', $text));
        if (count($lines) < 4 || !preg_match('/^\d+\.\d+\.\d+$/D', trim($lines[0]))) {
            throw new StereoNxException('manifest_format', 'Nepodporovaný formát seznamu firem Stereo NX.');
        }
        $companies = [];
        foreach (array_slice($lines, 3) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = explode('|', $line);
            if (count($parts) !== 3 || !preg_match('/^(0|[1-9][0-9]{0,8})$/D', $parts[0])
                || trim($parts[1]) === '' || trim($parts[2]) === '') {
                throw new StereoNxException('manifest_company', 'Neplatný záznam firmy v seznamu zálohy.');
            }
            $index = (int) $parts[0];
            if (isset($companies[$index])) {
                throw new StereoNxException('manifest_duplicate', 'Seznam obsahuje duplicitní index firmy.');
            }
            // source_key není IČO. Jeho význam se nesmí odvozovat z délky ani popisku.
            $companies[$index] = ['index' => $index, 'prefix' => 'Firma_' . $index . '/',
                'label' => trim($parts[1]), 'source_key' => trim($parts[2])];
        }
        if ($companies === []) {
            throw new StereoNxException('manifest_empty', 'Záloha neobsahuje žádnou firmu.');
        }
        return array_values($companies);
    }
}
