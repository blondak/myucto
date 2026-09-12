<?php

declare(strict_types=1);

namespace MyInvoice\Service\Intrastat;

final class InstatEvoCsvExporter
{
    /** @param list<list<string>> $rows */
    public function export(array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('CSV export se nepodařilo připravit.');
        }

        foreach ($rows as $row) {
            if (count($row) !== 20) {
                fclose($stream);
                throw new \InvalidArgumentException('Řádek InstatEvo musí mít přesně 20 sloupců.');
            }
            $safe = array_map([self::class, 'safeCell'], $row);
            if (fputcsv($stream, $safe, ';', '"', '', "\r\n") === false) {
                fclose($stream);
                throw new \RuntimeException('CSV export se nepodařilo zapsat.');
            }
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if ($csv === false || !mb_check_encoding($csv, 'UTF-8')) {
            throw new \RuntimeException('CSV export není platný UTF-8.');
        }

        return $csv;
    }

    private static function safeCell(string $value): string
    {
        $value = preg_replace('/[\r\n]+/u', ' ', $value) ?? $value;
        if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1) {
            return "'" . $value;
        }
        return $value;
    }
}
