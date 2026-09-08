<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Match;

/** Archivní identita nikdy nesmí být interpretována jako ID živého dokladu. */
final class BankMatchArchivedDocuments
{
    public const COLUMN = 'archived_document_references';

    /**
     * @param array<string,mixed> $row
     * @param callable(string,int):?int $owner NULL pouze pro neexistující doklad
     * @return array<string,mixed>
     */
    public static function detachMissing(string $table, array $row, string $backupId, callable $owner): array
    {
        self::assertTable($table);
        if (!self::uuid($backupId) || !is_int($row['supplier_id'] ?? null) || $row['supplier_id'] < 1) {
            throw new \InvalidArgumentException('Neplatný zdroj archivní identity.');
        }
        self::assertRow($table, $row);
        $documents = self::documents($row);
        $payload = self::payload($table, $row);
        $slots = self::slots($table, $payload);
        foreach ($slots as $slot) {
            $id = self::value($payload, $slot);
            if ($id === null) {
                continue;
            }
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('Neplatné ID historického dokladu.');
            }
            $target = $slot['field'] === 'purchase_invoice_id' ? 'purchase_invoices' : 'invoices';
            $supplier = $owner($target, $id);
            if ($supplier !== null) {
                if ($supplier !== $row['supplier_id']) {
                    throw new \InvalidArgumentException('Historický doklad patří jiné firmě.');
                }
                continue;
            }
            $documents[] = $slot + [
                'table' => $target, 'id' => $id,
                'supplier_id' => $row['supplier_id'], 'backup_id' => $backupId,
            ];
            self::clear($payload, $slot);
        }
        if ($documents === self::documents($row)) {
            return $row;
        }
        $originalStatus = $table === 'bank_match_suggestions' ? $row['status'] : null;
        if ($table === 'bank_match_suggestions') {
            $row['candidates_json'] = self::json($payload);
            if ($row['status'] === 'pending') {
                $row['status'] = 'superseded';
            }
        } else {
            $row['invoice_ids'] = $payload['invoice_ids'] === null ? null : self::json($payload['invoice_ids']);
            $row['purchase_invoice_id'] = $payload['purchase_invoice_id'];
        }
        $previous = ($row[self::COLUMN] ?? null) === null ? null : self::decode($row[self::COLUMN]);
        $row[self::COLUMN] = self::json(['version' => 1, 'documents' => $documents,
            'original_status' => $previous === null ? $originalStatus : $previous['original_status']]);
        self::assertRow($table, $row);
        return $row;
    }

    /** @param array<string,mixed> $row */
    public static function assertRow(string $table, array $row): void
    {
        self::assertTable($table);
        $documents = self::documents($row);
        if ($documents !== []) {
            $originalStatus = self::decode($row[self::COLUMN])['original_status'];
            if (($table === 'bank_match_audit') !== ($originalStatus === null)) {
                throw new \InvalidArgumentException('Archivní stav neodpovídá druhu historie.');
            }
        }
        if ($documents !== [] && $table === 'bank_match_suggestions' && ($row['status'] ?? null) === 'pending') {
            throw new \InvalidArgumentException('Archivní návrh nesmí být aktivní.');
        }
        $payload = self::payload($table, $row);
        $slots = self::slots($table, $payload);
        $seen = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                throw new \InvalidArgumentException('Neplatná archivní identita.');
            }
            $keys = array_keys($document);
            sort($keys);
            if ($keys !== ['backup_id', 'candidate', 'field', 'id', 'position', 'supplier_id', 'table']
                || !is_int($document['id']) || $document['id'] < 1
                || !is_int($document['supplier_id']) || $document['supplier_id'] < 1
                || !self::uuid($document['backup_id'])
            ) {
                throw new \InvalidArgumentException('Neplatná archivní identita.');
            }
            $slot = ['candidate' => $document['candidate'], 'field' => $document['field'],
                'position' => $document['position']];
            $key = self::json($slot);
            if (!in_array($slot, $slots, true) || isset($seen[$key])
                || $document['table'] !== ($slot['field'] === 'purchase_invoice_id' ? 'purchase_invoices' : 'invoices')
                || self::value($payload, $slot) !== null
            ) {
                throw new \InvalidArgumentException('Archivní identita koliduje s živým odkazem.');
            }
            $seen[$key] = true;
        }
        foreach ($slots as $slot) {
            if ($slot['position'] !== null && self::value($payload, $slot) === null
                && !isset($seen[self::json($slot)])
            ) {
                throw new \InvalidArgumentException('Prázdný člen seznamu nemá archivní identitu.');
            }
        }
    }

    /** @param array<string,mixed> $row */
    public static function assertAcceptable(array $row): void
    {
        if (($row[self::COLUMN] ?? null) !== null) {
            throw new MatchSuggestionException('archived_candidate',
                'Archivní návrh obsahuje nedostupný doklad a nelze jej přijmout.', 409);
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return list<mixed>
     */
    private static function documents(array $row): array
    {
        $raw = $row[self::COLUMN] ?? null;
        if ($raw === null) {
            return [];
        }
        $value = self::decode($raw);
        $keys = is_array($value) ? array_keys($value) : [];
        sort($keys);
        if ($keys !== ['documents', 'original_status', 'version'] || $value['version'] !== 1
            || !in_array($value['original_status'], [null, 'pending', 'accepted', 'rejected', 'superseded', 'auto_applied'], true)
            || !is_array($value['documents']) || !array_is_list($value['documents'])
            || $value['documents'] === [] || count($value['documents']) > 10000
        ) {
            throw new \InvalidArgumentException('Neplatná obálka archivních dokladů.');
        }
        return $value['documents'];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<mixed>
     */
    private static function payload(string $table, array $row): array
    {
        if ($table === 'bank_match_suggestions') {
            $payload = self::decode($row['candidates_json'] ?? null);
            if (!is_array($payload) || !array_is_list($payload)) {
                throw new \InvalidArgumentException('Neplatní kandidáti párování.');
            }
            return $payload;
        }
        return ['invoice_ids' => ($row['invoice_ids'] ?? null) === null
            ? null : self::decode($row['invoice_ids']),
            'purchase_invoice_id' => $row['purchase_invoice_id'] ?? null];
    }

    /**
     * @param array<mixed> $payload
     * @return list<array{candidate:?int,field:string,position:?int}>
     */
    private static function slots(string $table, array $payload): array
    {
        $slots = [];
        $candidates = $table === 'bank_match_suggestions' ? $payload : [$payload];
        if (!array_is_list($candidates)) {
            throw new \InvalidArgumentException('Kandidáti musí být seznam.');
        }
        foreach ($candidates as $index => $candidate) {
            if (!is_array($candidate) || array_is_list($candidate)) {
                throw new \InvalidArgumentException('Neplatný historický kandidát.');
            }
            if ($table === 'bank_match_suggestions'
                && (!in_array($candidate['type'] ?? null, ['invoice', 'purchase_invoice', 'split'], true)
                    || array_diff(array_keys($candidate), [
                        'type', 'invoice_id', 'invoice_ids', 'purchase_invoice_id', 'signals',
                        'flags', 'fee_amount', 'overpayment_amount', 'display', 'score', 'deterministic_core',
                    ]) !== [])
            ) {
                throw new \InvalidArgumentException('Neznámý tvar historického kandidáta.');
            }
            foreach (['invoice_id', 'invoice_ids', 'purchase_invoice_id'] as $field) {
                if (!array_key_exists($field, $candidate)) {
                    continue;
                }
                if ($field === 'invoice_ids' && $candidate[$field] === null) {
                    continue;
                }
                $values = $field === 'invoice_ids' ? $candidate[$field] : [$candidate[$field]];
                if (!is_array($values) || !array_is_list($values)) {
                    throw new \InvalidArgumentException('Neplatný seznam historických dokladů.');
                }
                foreach ($values as $position => $value) {
                    if ($value !== null && (!is_int($value) || $value < 1)) {
                        throw new \InvalidArgumentException('Neplatné ID historického dokladu.');
                    }
                    $slots[] = ['candidate' => $table === 'bank_match_suggestions' ? $index : null,
                        'field' => $field, 'position' => $field === 'invoice_ids' ? $position : null];
                }
            }
        }
        return $slots;
    }

    /**
     * @param array<mixed> $payload
     * @param array{candidate:?int,field:string,position:?int} $slot
     */
    private static function value(array $payload, array $slot): mixed
    {
        $candidate = $slot['candidate'] === null ? $payload : $payload[$slot['candidate']];
        return $slot['position'] === null ? $candidate[$slot['field']] : $candidate[$slot['field']][$slot['position']];
    }

    /**
     * @param array<mixed> $payload
     * @param array{candidate:?int,field:string,position:?int} $slot
     */
    private static function clear(array &$payload, array $slot): void
    {
        if ($slot['candidate'] === null) {
            $candidate =& $payload;
        } else {
            $candidate =& $payload[$slot['candidate']];
        }
        if ($slot['position'] === null) {
            $candidate[$slot['field']] = null;
        } else {
            $candidate[$slot['field']][$slot['position']] = null;
        }
    }

    private static function assertTable(string $table): void
    {
        if (!in_array($table, ['bank_match_audit', 'bank_match_suggestions'], true)) {
            throw new \InvalidArgumentException('Archivní výjimka neplatí pro tento objekt.');
        }
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private static function decode(mixed $value): mixed
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Archivní payload není JSON.');
        }
        return json_decode($value, true, 64, JSON_THROW_ON_ERROR);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
