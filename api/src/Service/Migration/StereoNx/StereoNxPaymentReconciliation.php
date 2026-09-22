<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

/** Ověření zdrojových vazeb, nikoli párování podle VS nebo odhad stavu podle stáří. */
final class StereoNxPaymentReconciliation
{
    /**
     * Kontroluje domácí měnu: CBankap/CPokl.Castka proti Cpz.Uhrazeno.
     * Cizoměnové vazby se hlásí jako neověřené, nikoli chybně porovnávají s CZK.
     * @param iterable<array<string,mixed>> $documents Cpz
     * @param iterable<array<string,mixed>> $bank CBankap
     * @param iterable<array<string,mixed>> $cash CPokl
     * @return array<string,int|bool>
     */
    public static function check(iterable $documents, iterable $bank, iterable $cash): array
    {
        $docs = [];
        $totals = [];
        $report = ['documents' => 0, 'linked_movements' => 0, 'unlinked_movements' => 0,
            'orphan_links' => 0, 'matched_documents' => 0, 'amount_mismatches' => 0,
            'direction_mismatches' => 0, 'foreign_currency_unverified' => 0,
            'paid_flag_disagreements' => 0, 'ok' => false];
        foreach ($documents as $row) {
            $key = self::key($row);
            if ($key === null || isset($docs[$key])) {
                throw new StereoNxException('document_identity', 'Chybějící nebo duplicitní identita dokladu Stereo NX.');
            }
            self::direction($row);
            $docs[$key] = $row;
            $report['documents']++;
        }
        $unverified = [];
        $foreign = [];
        foreach ([$bank, $cash] as $rows) {
            foreach ($rows as $row) {
                $key = self::key($row);
                if ($key === null) {
                    $report['unlinked_movements']++;
                    continue;
                }
                $report['linked_movements']++;
                if (!isset($docs[$key])) {
                    $report['orphan_links']++;
                    continue;
                }
                $doc = $docs[$key];
                if (self::direction($row) !== self::direction($doc)) {
                    $report['direction_mismatches']++;
                    $unverified[$key] = true;
                    continue;
                }
                if (trim((string) ($row['MenaCizi'] ?? '')) !== '' || !self::domestic($doc)) {
                    $unverified[$key] = true;
                    $foreign[$key] = true;
                    continue;
                }
                $totals[$key] = ($totals[$key] ?? 0) + self::cents($row['Castka'] ?? null);
            }
        }
        foreach ($docs as $key => $row) {
            if (!self::domestic($row) || isset($unverified[$key])) {
                if (!self::domestic($row) || isset($foreign[$key])) {
                    $report['foreign_currency_unverified']++;
                }
                continue;
            }
            $paid = self::cents($row['Uhrazeno'] ?? null);
            if (($totals[$key] ?? 0) === $paid) {
                $report['matched_documents']++;
            } else {
                $report['amount_mismatches']++;
            }
            $total = self::cents($row['Celkem'] ?? null);
            if ($total > 0 && $paid >= $total && ($row['UhrazenoVse'] ?? null) !== true) {
                $report['paid_flag_disagreements']++;
            }
        }
        $report['ok'] = $report['orphan_links'] === 0 && $report['amount_mismatches'] === 0
            && $report['direction_mismatches'] === 0 && $report['foreign_currency_unverified'] === 0;
        return $report;
    }

    /** @param array<string,mixed> $row */
    private static function domestic(array $row): bool
    {
        return in_array(trim((string) ($row['Mena'] ?? '')), ['Kč', 'CZK'], true)
            && isset($row['Kurz'], $row['KurzMn']) && (float) $row['Kurz'] === 1.0 && (float) $row['KurzMn'] === 1.0;
    }

    /** @param array<string,mixed> $row */
    private static function key(array $row): ?string
    {
        if (!array_key_exists('DoklSRada', $row) || !array_key_exists('DoklSCislo', $row)) {
            throw new StereoNxException('link_schema', 'Chybí sloupce vazby na doklad.');
        }
        $series = trim((string) $row['DoklSRada']);
        $number = trim((string) $row['DoklSCislo']);
        if ($series === '' && ($number === '' || $number === '0')) {
            return null;
        }
        if ($series === '' || $number === '' || $number === '0') {
            throw new StereoNxException('link_incomplete', 'Neúplná vazba na doklad Stereo NX.');
        }
        return json_encode([$series, $number], JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $row */
    private static function direction(array $row): string
    {
        $direction = $row['SmerPlatby'] ?? null;
        if (!in_array($direction, ['P', 'V'], true)) {
            throw new StereoNxException('payment_direction', 'Neznámý směr platby Stereo NX.');
        }
        return $direction;
    }

    private static function cents(mixed $value): int
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs($value) > 1.0e12) {
            throw new StereoNxException('payment_amount', 'Chybějící nebo neplatná částka Stereo NX.');
        }
        return (int) round($value * 100, 0, PHP_ROUND_HALF_UP);
    }
}
