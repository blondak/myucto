<?php

declare(strict_types=1);

namespace MyInvoice\Service;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Zřetězení auditní stopy hashem — § 33a ZoÚ.
 *
 * `activity_log` byla běžná tabulka: `JournalIntegrityService` sice hlídá nekonzistence
 * deníku, ale přepsání SAMOTNÉHO LOGU nezjistí. Každý nový záznam proto nese hash svého
 * obsahu zřetězený s hashem předchozího; změna nebo smazání kteréhokoli záznamu tím
 * rozbije všechny následující.
 *
 * ── Hranice toho, co to dokáže ──────────────────────────────────────────────────────
 * Nechrání to před útočníkem, který má právo zápisu a přepočítá celý řetěz znovu. Proti
 * tomu pomáhá až kotva mimo databázi (pravidelný export otisku, podepsaná záloha). Řetěz
 * dělá zásah PROKAZATELNÝM, ne nemožným — a tvrdit víc by bylo horší než netvrdit nic.
 *
 * ── Proč se historie nedopočítává ───────────────────────────────────────────────────
 * Záznamy z doby před zavedením řetězu zůstávají bez hashe. Dopočítat je zpětně by
 * vytvořilo řetěz, který nedokazuje nic — hash spočtený dnes nad daty, která mohla být
 * kdykoli změněna, jen dodává zdání důvěryhodnosti. {@see verify()} proto historii
 * výslovně odlišuje od chráněné části.
 */
final class ActivityLogHashChain
{
    /** Sloupce vstupující do hashe. Pořadí je součást definice — změna rozbije řetěz. */
    private const HASHED_COLUMNS = [
        'id', 'supplier_id', 'user_id', 'action', 'entity_type', 'entity_id',
        'payload', 'ip', 'user_agent', 'created_at',
    ];

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasColumn('activity_log', 'hash');
    }

    /**
     * Doplní hash čerstvě vloženému záznamu. Volá se hned po INSERT, uvnitř téže
     * transakce jako zapisovaná mutace.
     *
     * Předchozí článek se čte pod zámkem: bez něj by dvě souběžné transakce přečetly týž
     * `prev_hash` a řetěz by se rozvětvil — ověření by pak hlásilo porušení tam, kde žádné
     * není. Zámek se drží do commitu VNĚJŠÍ transakce, jinak by okno pro rozvětvení
     * zůstalo otevřené (nezacommitovaný článek další transakce nevidí).
     *
     * Zamyká se JEDEN ZNÁMÝ ŘÁDEK hlavy řetězu (`activity_log_chain_head`, migrace 1171),
     * ne poslední záznam nalezený přes `ORDER BY id DESC LIMIT 1`. Ten dotaz skenoval
     * ROZSAH, takže v REPEATABLE READ bral gap locky a spolu s insert-intention zámkem
     * nového řádku se dvě transakce zaklesly — naměřeno jako
     * `SQLSTATE[40001] 1213 Deadlock`. Bodový zámek nad primárním klíčem má vždy stejné
     * pořadí, takže souběh je FRONTA, ne deadlock. U sériového řetězu je fronta správné
     * chování; deadlock ne.
     */
    public function seal(int $id): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $pdo = $this->db->pdo();
        $inTx = $pdo->inTransaction();
        $useHead = $this->db->hasColumn('activity_log_chain_head', 'last_hash');

        if ($useHead) {
            $prevStmt = $pdo->prepare(
                'SELECT last_hash FROM activity_log_chain_head WHERE id = 1'
                . ($inTx ? ' FOR UPDATE' : '')
            );
            $prevStmt->execute();
        } else {
            // Fallback pro instalaci bez migrace 1171 — chová se jako dřív, včetně
            // rizika deadlocku. Tiše NEPŘESKAKUJE: bez `prev_hash` by se řetěz přerušil.
            $prevStmt = $pdo->prepare(
                'SELECT hash FROM activity_log
                  WHERE id < ? AND hash IS NOT NULL
               ORDER BY id DESC LIMIT 1' . ($inTx ? ' FOR UPDATE' : '')
            );
            $prevStmt->execute([$id]);
        }
        $prev = $prevStmt->fetchColumn();
        $prevHash = $prev === false || $prev === null ? null : (string) $prev;

        $rowStmt = $pdo->prepare(
            'SELECT ' . implode(', ', self::HASHED_COLUMNS) . ' FROM activity_log WHERE id = ?'
        );
        $rowStmt->execute([$id]);
        $row = $rowStmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return;
        }

        $hash = self::hashOf($row, $prevHash);
        $pdo->prepare('UPDATE activity_log SET prev_hash = ?, hash = ? WHERE id = ?')
            ->execute([$prevHash, $hash, $id]);

        if ($useHead) {
            // Posun hlavy je součástí TÉŽE transakce jako zapečetění — kdyby se rozešly,
            // ukazovala by hlava na článek, který po rollbacku neexistuje.
            $pdo->prepare('UPDATE activity_log_chain_head SET last_id = ?, last_hash = ? WHERE id = 1')
                ->execute([$id, $hash]);
        }
    }

    /**
     * Ověří zapečetěný řetěz.
     *
     * `$fromId` omezí ověření na úsek od daného záznamu. Používá se v testech, kde do
     * `activity_log` zapisují i jiné testy v transakcích, které se rollbacknou — článek,
     * na který mezitím navázal jiný, pak zmizí a globální ověření by hlásilo porušení
     * tam, kde v reálném provozu žádné není. Produkční ověření běží bez omezení.
     *
     * @return array{
     *   checked:int, unprotected:int, first_protected_id:?int,
     *   broken:list<array{id:int, reason:string}>, ok:bool
     * }
     */
    public function verify(?int $fromId = null): array
    {
        if (!$this->isAvailable()) {
            return ['checked' => 0, 'unprotected' => 0, 'first_protected_id' => null, 'broken' => [], 'ok' => true];
        }
        $pdo = $this->db->pdo();

        $where = $fromId === null ? '' : ' AND id >= ' . (int) $fromId;
        $unprotected = (int) $pdo->query('SELECT COUNT(*) FROM activity_log WHERE hash IS NULL' . $where)->fetchColumn();

        $stmt = $pdo->query(
            'SELECT ' . implode(', ', self::HASHED_COLUMNS) . ', prev_hash, hash
               FROM activity_log WHERE hash IS NOT NULL' . $where . ' ORDER BY id'
        );

        $checked = 0;
        $firstId = null;
        $broken = [];
        $expectedPrev = null;

        foreach ($stmt as $row) {
            $id = (int) $row['id'];
            $firstId ??= $id;
            $checked++;

            // Prvnímu článku se předchozí hash neověřuje — žádný nemá.
            if ($checked > 1 && (string) ($row['prev_hash'] ?? '') !== (string) $expectedPrev) {
                $broken[] = ['id' => $id, 'reason' => 'navazuje na jiný záznam, než na který má (řetěz přerušen)'];
            }

            $data = array_intersect_key($row, array_flip(self::HASHED_COLUMNS));
            if (self::hashOf($data, $row['prev_hash'] !== null ? (string) $row['prev_hash'] : null) !== (string) $row['hash']) {
                $broken[] = ['id' => $id, 'reason' => 'obsah záznamu neodpovídá jeho hashi (byl změněn)'];
            }

            $expectedPrev = (string) $row['hash'];
        }

        return [
            'checked' => $checked,
            'unprotected' => $unprotected,
            'first_protected_id' => $firstId,
            'broken' => $broken,
            'ok' => $broken === [],
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function hashOf(array $row, ?string $prevHash): string
    {
        $parts = [];
        foreach (self::HASHED_COLUMNS as $col) {
            // Oddělovač `\x1F` (unit separator) se v datech nevyskytuje, takže hodnoty
            // nejdou „přelít" jedna do druhé (payload končící tam, kde začíná ip).
            $parts[] = $row[$col] === null ? "\x00" : (string) $row[$col];
        }
        $parts[] = $prevHash ?? '';

        return hash('sha256', implode("\x1F", $parts));
    }
}
