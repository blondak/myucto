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

    /**
     * `created_at` je TIMESTAMP, takže ho MariaDB renderuje podle zóny SESSION —
     * táž řádka vrátí jiný řetězec, jakmile se zóna zapečeťujícího a ověřujícího
     * spojení liší. To se dělo: `Connection` připínala session na PEVNÝ offset
     * (`date('P')`), takže po přechodu na zimní čas se všechny letní záznamy
     * renderovaly o hodinu jinak a `verify()` je hlásil jako pozměněné — auditní
     * stopa tvrdila, že s ní někdo manipuloval. Do hashe proto vstupuje hodnota
     * převedená do UTC, která je na zóně spojení nezávislá.
     *
     * `COALESCE` je pojistka pro session se zónou `SYSTEM`, nad kterou `CONVERT_TZ`
     * vrací NULL. Bez ní by se do hashe dostala prázdná hodnota — tedy stejný otisk
     * pro záznamy s různým časem.
     */
    private const CREATED_AT_UTC_SQL =
        "DATE_FORMAT(COALESCE(CONVERT_TZ(created_at, @@session.time_zone, '+00:00'), created_at), "
        . "'%Y-%m-%d %H:%i:%s') AS created_at";

    private ?int $watermark = null;
    private bool $watermarkResolved = false;

    public function __construct(private readonly Connection $db) {}

    /**
     * Seznam sloupců do SELECTu — `created_at` kanonizovaný do UTC, ostatní tak, jak jsou.
     */
    private static function selectList(): string
    {
        $columns = [];
        foreach (self::HASHED_COLUMNS as $col) {
            $columns[] = $col === 'created_at' ? self::CREATED_AT_UTC_SQL : $col;
        }

        return implode(', ', $columns);
    }

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
            'SELECT ' . self::selectList() . ' FROM activity_log WHERE id = ?'
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
            'SELECT ' . self::selectList() . ', prev_hash, hash
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
            $prevOfRow = $row['prev_hash'] !== null ? (string) $row['prev_hash'] : null;
            if (!in_array((string) $row['hash'], $this->hashCandidates($data, $prevOfRow), true)) {
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
     * Hashe, které se u JIŽ ZAPEČETĚNÉHO článku uznávají.
     *
     * Články zapečetěné před kanonizací {@see self::CREATED_AT_UTC_SQL} mají v hashi
     * `created_at` vyrenderovaný v zóně session, ne v UTC. Zpětně je přepočítat nelze
     * (přepsat hash auditní stopy je přesně to, co má řetěz znemožnit), takže se
     * u nich uznává i tahle historická podoba.
     *
     * ⚠️ Výjimka MUSÍ mít hranici. Obě podoby popisují týž okamžik, takže tam, kde se
     * uznávají obě, projde ověřením i posun `created_at` přesně o offset zóny — a to
     * je díra v tom, co má řetěz dokazovat. Hranicí je watermark
     * `activity_log_chain_head.utc_created_at_from_id` (migrace 1528): pod ním leží
     * články zapečetěné starým kódem, od něj výš platí jediný, kanonický hash.
     *
     * Bez watermarku (instalace bez migrace 1528) se historická podoba uznává všem —
     * jinak by ověření po nasazení hlásilo celou existující stopu jako pozměněnou.
     *
     * @param array<string,mixed> $row  `created_at` už v UTC
     * @return list<string>
     */
    private function hashCandidates(array $row, ?string $prevHash): array
    {
        $candidates = [self::hashOf($row, $prevHash)];

        $watermark = $this->utcHashWatermark();
        if ($watermark !== null && (int) ($row['id'] ?? 0) >= $watermark) {
            return $candidates;
        }

        $utc = $row['created_at'] ?? null;
        if (is_string($utc) && $utc !== '') {
            try {
                $local = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d H:i:s');
                if ($local !== $utc) {
                    $candidates[] = self::hashOf(['created_at' => $local] + $row, $prevHash);
                }
            } catch (\Throwable) {
                // Nečitelné razítko — zůstane jen kanonický kandidát.
            }
        }

        return $candidates;
    }

    /**
     * Od kterého `id` platí výhradně kanonický UTC hash.
     *
     * `null` = watermark není k dispozici (instalace bez migrace 1528), pak se
     * historická podoba uznává všem článkům. Čte se jednou za instanci; během
     * ověření se hodnota měnit nemůže.
     */
    private function utcHashWatermark(): ?int
    {
        if ($this->watermarkResolved) {
            return $this->watermark;
        }
        $this->watermarkResolved = true;

        if (!$this->db->hasColumn('activity_log_chain_head', 'utc_created_at_from_id')) {
            return $this->watermark = null;
        }

        $value = $this->db->pdo()
            ->query('SELECT utc_created_at_from_id FROM activity_log_chain_head WHERE id = 1')
            ->fetchColumn();

        return $this->watermark = ($value === false || $value === null) ? null : (int) $value;
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
