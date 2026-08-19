<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

final class TaxSubmissionFilename
{
    /** @param array<string,mixed> $submission */
    public static function forSnapshot(
        array $submission,
        string $suffix,
        ?int $attemptId = null,
        ?\DateTimeInterface $timestamp = null,
    ): string {
        $formCode = self::safePart(strtoupper((string) ($submission['form_code'] ?? 'EPO')), 'EPO');
        $period = (string) ((int) ($submission['period_year'] ?? 0));
        if (($submission['period_month'] ?? null) !== null) {
            $period .= '-' . sprintf('%02d', (int) $submission['period_month']);
        } elseif (($submission['period_quarter'] ?? null) !== null) {
            $period .= '-Q' . (int) $submission['period_quarter'];
        }

        $parts = [$formCode, $period];
        $variant = strtoupper(trim((string) ($submission['form_variant'] ?? '')));
        if ($variant !== '' && $variant !== 'B') {
            $parts[] = self::safePart($variant, 'VAR');
        }

        $submissionId = (int) ($submission['id'] ?? $submission['submission_id'] ?? 0);
        if ($submissionId > 0) {
            $parts[] = 's' . $submissionId;
        }
        if ($attemptId !== null && $attemptId > 0) {
            $parts[] = 'a' . $attemptId;
        }

        $timestamp ??= self::snapshotTimestamp($submission);
        $parts[] = $timestamp->format('Ymd-His-u');

        $safeSuffix = self::safeSuffix($suffix);
        $base = implode('-', $parts);

        // Holá přípona (např. 'xml') se připojuje tečkou, ne pomlčkou — jinak by
        // se ".xml" změnilo na "-xml" a soubor by nešel podle přípony rozeznat.
        // Sufix ve tvaru "název.přípona" (např. 'archive.xml', 'confirmation.p7s')
        // se naopak od zbytku jména odděluje pomlčkou jako dosud.
        if (!str_contains($safeSuffix, '.')) {
            return $base . '.' . $safeSuffix;
        }

        return $base . '-' . $safeSuffix;
    }

    /** @param array<string,mixed> $submission */
    private static function snapshotTimestamp(array $submission): \DateTimeImmutable
    {
        $generatedAt = trim((string) ($submission['generated_at'] ?? ''));
        if ($generatedAt !== '') {
            try {
                return new \DateTimeImmutable($generatedAt);
            } catch (\Exception) {
                return new \DateTimeImmutable('now');
            }
        }
        return new \DateTimeImmutable('now');
    }

    private static function safePart(string $value, string $fallback): string
    {
        $safe = preg_replace('/[^A-Z0-9._-]+/i', '-', trim($value)) ?? '';
        $safe = trim($safe, '-.');
        return $safe !== '' ? $safe : $fallback;
    }

    private static function safeSuffix(string $suffix): string
    {
        $safe = self::safePart($suffix, 'artifact.bin');
        return mb_substr($safe, 0, 120);
    }
}
