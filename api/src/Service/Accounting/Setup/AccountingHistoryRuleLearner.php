<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Setup;

use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;

/**
 * Pravidla odvozená z toho, jak se přijaté faktury SKUTEČNĚ zaúčtovaly.
 *
 * Katalog frází a AI hádají z textu položky; zaúčtovaná historie (u převzatých dat
 * klidně deset let) říká, co u firmy fungovalo. Proto má přednost: dodavatel, jehož
 * doklady šly konzistentně na jeden účet, dostane pravidlo „dodavatel → účet".
 *
 * Učí se z celého dokladu, ne z řádku, protože řádek deníku na položku faktury
 * nevede. Doklad se počítá jen tehdy, když jeden účet nese aspoň DOMINANT_SHARE
 * jeho nákladové částky (haléřové zaokrouhlení na 548 ho nerozbije); smíšený doklad
 * zůstává ve jmenovateli jako nesouhlas, takže dodavatele, který posílá zboží
 * i dopravu na jedné faktuře, pravidlem nepřebijeme.
 *
 * Nejednoznačný dodavatel se zkusí rozdělit klíčovým slovem z popisu položek.
 * Když ani to nedá čistou shodu, pravidlo nevznikne: radši nic než hádání.
 *
 * Čistá třída bez DB, vstup skládá {@see AccountingSetupAnalysisService}.
 */
final class AccountingHistoryRuleLearner
{
    public const MIN_DOCUMENTS = 3;
    public const MIN_SHARE = 0.9;
    private const DOMINANT_SHARE = 0.95;
    private const RECENT_YEARS = 2;
    private const MIN_TOKEN_LENGTH = 4;
    private const MAX_SAMPLES = 3;
    /** Slova z hlavičky dokladu, ne z povahy plnění: „dobropis" neřekne, na co se účtuje. */
    private const STOPWORDS = [
        'faktura', 'fakturace', 'fakturujeme', 'dobropis', 'zaloha', 'zalohy', 'zalohova',
        'doklad', 'danovy', 'opravny', 'polozka', 'polozky', 'celkem', 'invoice', 'credit', 'note',
    ];

    /**
     * @param list<array{
     *     invoice_id:int,
     *     vendor_id:int,
     *     vendor_name:?string,
     *     date:string,
     *     accounts:array<string,float>,
     *     descriptions:list<string>
     * }> $documents
     * @return array{
     *     rules:list<array<string,mixed>>,
     *     ambiguous:list<array{vendor_id:int,vendor_name:?string,documents:int,accounts:array<string,int>}>,
     *     documents:int
     * }
     */
    public static function learn(array $documents): array
    {
        $byVendor = [];
        foreach ($documents as $document) {
            if ($document['vendor_id'] <= 0 || $document['accounts'] === []) {
                continue;
            }
            $byVendor[$document['vendor_id']][] = $document + ['account' => self::dominantAccount($document['accounts'])];
        }
        ksort($byVendor);

        $rules = [];
        $ambiguous = [];
        $learned = 0;
        foreach ($byVendor as $vendorId => $vendorDocuments) {
            usort($vendorDocuments, static fn (array $a, array $b): int => [$a['date'], $a['invoice_id']] <=> [$b['date'], $b['invoice_id']]);
            $vendorName = $vendorDocuments[count($vendorDocuments) - 1]['vendor_name'];

            $rule = self::vendorRule($vendorDocuments, 'all')
                ?? self::vendorRule(self::recent($vendorDocuments), 'recent');
            if ($rule !== null) {
                $rules[] = $rule + ['vendor_id' => $vendorId, 'vendor_name' => $vendorName, 'keyword' => null];
                $learned += $rule['documents'];
                continue;
            }

            $keywordRules = self::keywordRules($vendorDocuments);
            foreach ($keywordRules as $keywordRule) {
                $rules[] = $keywordRule + ['vendor_id' => $vendorId, 'vendor_name' => $vendorName];
                $learned += $keywordRule['documents'];
            }
            if ($keywordRules === [] && count($vendorDocuments) >= self::MIN_DOCUMENTS) {
                $ambiguous[] = [
                    'vendor_id' => $vendorId,
                    'vendor_name' => $vendorName,
                    'documents' => count($vendorDocuments),
                    'accounts' => self::accountCounts($vendorDocuments),
                ];
            }
        }

        return ['rules' => $rules, 'ambiguous' => $ambiguous, 'documents' => $learned];
    }

    /** Účet, který nese aspoň 95 % nákladové částky dokladu; jinak null (smíšený doklad). */
    public static function dominantAccount(array $accounts): ?string
    {
        $total = 0.0;
        foreach ($accounts as $amount) {
            $total += abs((float) $amount);
        }
        if (count($accounts) === 1) {
            return (string) array_key_first($accounts);
        }
        if ($total <= 0.0) {
            return null;
        }
        foreach ($accounts as $code => $amount) {
            if (abs((float) $amount) / $total >= self::DOMINANT_SHARE) {
                return (string) $code;
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $documents */
    private static function vendorRule(array $documents, string $window): ?array
    {
        if (count($documents) < self::MIN_DOCUMENTS) {
            return null;
        }
        $counts = self::accountCounts($documents);
        if ($counts === []) {
            return null;
        }
        $account = (string) array_key_first($counts);
        $share = $counts[$account] / count($documents);
        if ($share < self::MIN_SHARE) {
            return null;
        }
        return self::evidence(
            array_values(array_filter($documents, static fn (array $d): bool => $d['account'] === $account)),
            $account,
            count($documents),
            $counts,
        ) + ['window' => $window];
    }

    /**
     * Klíčové slovo, které u dodavatele čistě odděluje jeden účet od ostatních.
     *
     * @param list<array<string,mixed>> $documents
     * @return list<array<string,mixed>>
     */
    private static function keywordRules(array $documents): array
    {
        $tokensByDocument = [];
        $documentsByToken = [];
        foreach ($documents as $index => $document) {
            $tokens = self::tokens($document['descriptions']);
            $tokensByDocument[$index] = $tokens;
            foreach ($tokens as $token) {
                $documentsByToken[$token][] = $index;
            }
        }

        $best = [];
        foreach ($documentsByToken as $token => $indexes) {
            if (count($indexes) < self::MIN_DOCUMENTS) {
                continue;
            }
            $hits = [];
            foreach ($indexes as $index) {
                $account = $documents[$index]['account'];
                if ($account !== null) {
                    $hits[$account] = ($hits[$account] ?? 0) + 1;
                }
            }
            if ($hits === []) {
                continue;
            }
            arsort($hits);
            $account = (string) array_key_first($hits);
            $share = $hits[$account] / count($indexes);
            if ($hits[$account] < self::MIN_DOCUMENTS || $share < self::MIN_SHARE) {
                continue;
            }
            $current = $best[$account] ?? null;
            $candidate = ['token' => (string) $token, 'indexes' => $indexes, 'hits' => $hits[$account]];
            if ($current === null
                || [$candidate['hits'], mb_strlen($candidate['token']), $current['token']]
                    > [$current['hits'], mb_strlen($current['token']), $candidate['token']]) {
                $best[$account] = $candidate;
            }
        }
        ksort($best);

        $rules = [];
        foreach ($best as $account => $choice) {
            $matched = array_map(static fn (int $index): array => $documents[$index], $choice['indexes']);
            $rules[] = self::evidence(
                array_values(array_filter($matched, static fn (array $d): bool => $d['account'] === (string) $account)),
                (string) $account,
                count($matched),
                self::accountCounts($matched),
            ) + ['window' => 'all', 'keyword' => $choice['token']];
        }
        return $rules;
    }

    /**
     * @param list<array<string,mixed>> $agreeing
     * @param array<string,int> $counts
     */
    private static function evidence(array $agreeing, string $account, int $documents, array $counts): array
    {
        $amount = 0.0;
        foreach ($agreeing as $document) {
            $amount += abs((float) ($document['accounts'][$account] ?? 0));
        }
        $samples = [];
        foreach (array_slice(array_reverse($agreeing), 0, self::MAX_SAMPLES) as $document) {
            $samples[] = [
                'purchase_invoice_id' => $document['invoice_id'],
                'date' => $document['date'],
                'description' => mb_substr((string) ($document['descriptions'][0] ?? ''), 0, 180),
            ];
        }
        unset($counts[$account]);
        return [
            'account' => $account,
            'documents' => $documents,
            'agreeing' => count($agreeing),
            'share' => round(count($agreeing) / max(1, $documents), 4),
            'amount' => round($amount, 2),
            'first_seen' => $agreeing[0]['date'] ?? null,
            'last_seen' => $agreeing[count($agreeing) - 1]['date'] ?? null,
            'other_accounts' => $counts,
            'invoice_ids' => array_map(static fn (array $d): int => $d['invoice_id'], $agreeing),
            'samples' => $samples,
        ];
    }

    /**
     * Posledních pár let dodavatele: osnova se za deset let převodu mění a starý účet,
     * který dnes už nikdo nepoužívá, by jinak zablokoval jinak jasné pravidlo.
     *
     * @param list<array<string,mixed>> $documents seřazené podle data
     * @return list<array<string,mixed>>
     */
    private static function recent(array $documents): array
    {
        $last = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $documents[count($documents) - 1]['date']);
        if ($last === false) {
            return [];
        }
        $from = $last->modify('-' . self::RECENT_YEARS . ' years')->format('Y-m-d');
        $recent = array_values(array_filter($documents, static fn (array $d): bool => (string) $d['date'] > $from));
        return count($recent) === count($documents) ? [] : $recent;
    }

    /**
     * @param list<array<string,mixed>> $documents
     * @return array<string,int> účet => počet dokladů, sestupně
     */
    private static function accountCounts(array $documents): array
    {
        $counts = [];
        foreach ($documents as $document) {
            $key = $document['account'] ?? null;
            if ($key !== null) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        uksort($counts, static fn (string|int $a, string|int $b): int => [$counts[$b], (string) $a] <=> [$counts[$a], (string) $b]);
        return $counts;
    }

    /**
     * @param list<string> $descriptions
     * @return list<string>
     */
    private static function tokens(array $descriptions): array
    {
        $tokens = [];
        foreach ($descriptions as $description) {
            foreach (explode(' ', BankMessageNormalizer::normalizeKeepDigits($description)) as $token) {
                if (strlen($token) >= self::MIN_TOKEN_LENGTH && preg_match('/[a-z]/', $token) === 1
                    && !in_array($token, self::STOPWORDS, true)) {
                    $tokens[$token] = true;
                }
            }
        }
        return array_map('strval', array_keys($tokens));
    }
}
