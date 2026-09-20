<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Zpětné dogenerování popisů v deníku ({@see JournalDescriptionBuilder}).
 *
 * Instalace, které deník převzaly z POHODY nebo Money S3, mají desítky tisíc zápisů
 * s popisem z jediného pole zdrojového systému („Fakturujeme Vám za …"), takže se
 * zápisy v seznamu nedají odlišit. Převod ale zápisům dosadí `source_id`
 * (DocumentLinker), takže doklad i protistrana jsou dohledatelné a popis jde složit
 * znovu — bez sahání na částky, účty, období a bez přečíslování dokladů.
 *
 * CO SE NIKDY NEPŘEPÍŠE
 * ------------------------------------------------------------------------------
 *  - ruční zápis (`source_type = 'manual'`) a vůbec každý typ mimo
 *    {@see JournalDescriptionBuilder::BUILDABLE} — uzávěrka, mzdy, majetek, zápočty
 *    mají popis z dat, která v deníku nejsou;
 *  - zápis bez `source_id` (storno, ruční zápis) — není z čeho skládat;
 *  - zápis, u kterého uživatel popis sám změnil (§35 inline editace zapisuje do
 *    auditní stopy `accounting.description_edited`, viz
 *    {@see \MyInvoice\Repository\JournalEntryRepository::updateDescription()}).
 *
 * Dosavadní popis se NEZAHAZUJE: vstupuje do nového jako poslední segment (detail),
 * takže text zadaný účetní v dialogu zaúčtování zůstane čitelný, jen dostane před
 * sebe identifikaci dokladu a protistranu. Skládání je idempotentní, takže opakované
 * spuštění nic dalšího nezmění.
 *
 * Zápis jde přímým UPDATE, ne přes {@see PostingService} — mění se JEN narativní
 * popis (§13 obsah zápisu), ne částky, účty ani období, takže nemá co rozvážit a
 * nesmí ho zastavit zavřené období ani zámek k datu. `row_version` se přesto
 * inkrementuje, ať si otevřený detail v prohlížeči všimne změny (CAS přes If-Match).
 */
final class JournalDescriptionRebuilder
{
    /** Kolik zápisů se zpracuje, když volající limit nezadá. */
    public const DEFAULT_LIMIT = 5000;

    public function __construct(
        private readonly Connection $db,
        private readonly JournalDescriptionBuilder $builder,
    ) {}

    /**
     * Spočítá, kolik zápisů by se změnilo, a vrátí návrh „před → po".
     * Prohlédne nejvýše `limit` zápisů (výchozí {@see DEFAULT_LIMIT}).
     *
     * @param array{supplier_id?:?int, source_type?:?string, limit?:?int, after_id?:int, entry_ids?:list<int>} $filter
     * @return list<array{id:int, supplier_id:int, source_type:string, source_id:int, before:?string, after:string}>
     */
    public function plan(array $filter = []): array
    {
        return $this->planFor($this->candidates($filter));
    }

    /**
     * Totéž jako {@see plan()}, ale navíc vrací `last_id` = id posledního PROHLÉDNUTÉHO
     * zápisu (ne posledního změněného). Volající tím stránkuje přes kurzor; `null`
     * znamená, že další dávka už není.
     *
     * @param array{supplier_id?:?int, source_type?:?string, limit?:?int, after_id?:int, entry_ids?:list<int>} $filter
     * @return array{items:list<array{id:int, supplier_id:int, source_type:string, source_id:int, before:?string, after:string}>, last_id:?int}
     */
    public function planBatch(array $filter = []): array
    {
        $rows = $this->candidates($filter);

        return [
            'items'   => $this->planFor($rows),
            'last_id' => $rows === [] ? null : (int) $rows[array_key_last($rows)]['id'],
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{id:int, supplier_id:int, source_type:string, source_id:int, before:?string, after:string}>
     */
    private function planFor(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $before = JournalDescriptionBuilder::clean($row['description']);
            $after  = $this->builder->forSource(
                (int) $row['supplier_id'],
                (string) $row['source_type'],
                (int) $row['source_id'],
                $before,
            );
            if ($after === null || $after === '' || $after === $before) {
                continue;
            }
            $out[] = [
                'id'          => (int) $row['id'],
                'supplier_id' => (int) $row['supplier_id'],
                'source_type' => (string) $row['source_type'],
                'source_id'   => (int) $row['source_id'],
                'before'      => $before,
                'after'       => $after,
            ];
        }

        return $out;
    }

    /**
     * Zapíše návrh z {@see plan()}. Vrací počet skutečně změněných zápisů.
     *
     * @param list<array{id:int, supplier_id:int, after:string}> $plan
     */
    public function apply(array $plan): int
    {
        if ($plan === []) {
            return 0;
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'UPDATE journal_entries
                SET description = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ?'
        );
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $changed = 0;
            foreach ($plan as $item) {
                $stmt->execute([$item['after'], $item['id'], $item['supplier_id']]);
                $changed += $stmt->rowCount();
            }
            if ($ownTx) {
                $pdo->commit();
            }

            return $changed;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Projde VŠECHNY zápisy odpovídající filtru (po dávkách `limit`) a popisy přepíše.
     * Zkratka pro volající, kteří návrh nepotřebují vidět — typicky dokončení převodu
     * z POHODY / Money S3, kde se `source_id` doplní až po importu deníku.
     *
     * Stránkuje přes kurzor `id`, ne přes OFFSET: přepsaný zápis z výběru vypadne
     * (jeho popis už odpovídá), takže by se s OFFSETem další dávka přeskakovala.
     *
     * @param array{supplier_id?:?int, source_type?:?string, limit?:?int, entry_ids?:list<int>} $filter
     */
    public function rebuild(array $filter = []): int
    {
        $changed = 0;
        $afterId = 0;
        // Pojistka proti nekonečné smyčce, kdyby kurzor nepostoupil (nemá jak, ale
        // dávkový běh nad cizími daty nesmí umět zacyklit import).
        for ($batch = 0; $batch < 10_000; $batch++) {
            $rows = $this->candidates(array_merge($filter, ['after_id' => $afterId]));
            if ($rows === []) {
                break;
            }
            $lastId = (int) $rows[array_key_last($rows)]['id'];
            if ($lastId <= $afterId) {
                break;
            }
            $afterId = $lastId;
            $changed += $this->apply($this->planFor($rows));
        }

        return $changed;
    }

    /**
     * @param array{supplier_id?:?int, source_type?:?string, limit?:?int, after_id?:int, entry_ids?:list<int>} $filter
     * @return list<array<string,mixed>>
     */
    private function candidates(array $filter): array
    {
        $types = JournalDescriptionBuilder::BUILDABLE;
        $sourceType = $filter['source_type'] ?? null;
        if ($sourceType !== null && $sourceType !== '') {
            if (!in_array($sourceType, $types, true)) {
                return [];
            }
            $types = [$sourceType];
        }

        $where  = ['je.source_id IS NOT NULL'];
        $params = [];

        $where[] = 'je.source_type IN (' . implode(', ', array_fill(0, count($types), '?')) . ')';
        foreach ($types as $t) {
            $params[] = $t;
        }

        if (isset($filter['supplier_id']) && $filter['supplier_id'] !== null) {
            $where[]  = 'je.supplier_id = ?';
            $params[] = (int) $filter['supplier_id'];
        }
        if ((int) ($filter['after_id'] ?? 0) > 0) {
            $where[]  = 'je.id > ?';
            $params[] = (int) $filter['after_id'];
        }
        $entryIds = array_values(array_filter(
            array_map(static fn (mixed $v): int => (int) $v, $filter['entry_ids'] ?? []),
            static fn (int $v): bool => $v > 0,
        ));
        if (($filter['entry_ids'] ?? null) !== null) {
            if ($entryIds === []) {
                return [];
            }
            $where[] = 'je.id IN (' . implode(', ', array_fill(0, count($entryIds), '?')) . ')';
            foreach ($entryIds as $id) {
                $params[] = $id;
            }
        }

        // Uživatelem ručně upravený popis (§35 inline editace) zůstává nedotčený.
        $where[] = "NOT EXISTS (SELECT 1 FROM activity_log al
                                 WHERE al.entity_type = 'journal_entry'
                                   AND al.entity_id = je.id
                                   AND al.action = 'accounting.description_edited')";

        $limit = (int) ($filter['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT je.id, je.supplier_id, je.source_type, je.source_id, je.description
               FROM journal_entries je
              WHERE ' . implode("\n                AND ", $where) . '
              ORDER BY je.id ASC
              LIMIT ' . $limit
        );
        $stmt->execute($params);

        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
