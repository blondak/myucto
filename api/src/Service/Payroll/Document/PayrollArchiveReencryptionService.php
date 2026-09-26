<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Přešifrování nešifrovaných mzdových dokumentů z doby před W30 / C-05.
 *
 * Starší pásky, mzdové listy a potvrzení leží v plaintextu na cestě
 * `payroll-documents/sup-{id}/{hh}/{hash}`. Čtecí cesta je umí vydat dál, ale
 * dokud tam leží, adresář je čitelnou mzdovou databází firmy a krypto-výmaz na
 * ně nedosáhne. Tahle služba je přesune do šifrovaného rozvržení se subjektem.
 *
 * ── Pořadí kroků u jednoho souboru ──────────────────────────────────────────
 * 1. Přečte plaintext a ověří, že sha256 obsahu odpovídá názvu souboru. Když
 *    ne, soubor se nechá být - patří k ručnímu posouzení, ne k přepisu.
 * 2. Dohledá subjekty, které na obsah odkazují (`employee_scope_id`
 *    v `payroll_generated_documents`). Tentýž obsah může patřit víc
 *    subjektům; každý dostane vlastní kopii pod vlastním klíčem.
 * 3. Zapíše šifrovanou kopii ({@see PayrollDocumentStorage::store()} - dočasný
 *    soubor a přejmenování, tedy atomicky) a ZNOVU ji přečte cestou, která na
 *    legacy nespadne ({@see PayrollDocumentStorage::readEncryptedVerified()}).
 *    Hash po dešifrování musí sedět s hashem před zápisem.
 * 4. Teprve když sedí všechny kopie, smaže originál. Selže-li cokoli dřív,
 *    originál zůstává a další běh začne znovu od kroku 1 - existující
 *    šifrovanou kopii `store()` jen ověří, nepřepíše.
 *
 * Evidence (`payroll_generated_documents`, append-only) se nemění: `storage_key`
 * je hash plaintextu a ten zůstává stejný.
 *
 * ── Zvláštní případy ────────────────────────────────────────────────────────
 * - **Osiřelý soubor** (žádný řádek evidence na něj neodkazuje) se ve výchozím
 *   stavu jen vykáže. S `includeOrphans` se zašifruje firemním klíčem
 *   (subjekt 0) - nic se nemaže, jen přestane ležet čitelně.
 * - **Vymazaná osoba** (datový klíč zahozen krypto-výmazem): šifrovat pro ni
 *   nejde a ani nemá - výmaz říká, že obsah nesmí být čitelný. Soubor se
 *   vykáže; s `purgeErased` se smaže (stejně jako to dělá
 *   {@see PayrollDocumentCryptoErasure} u výmazů provedených po C-05). Má-li
 *   tentýž obsah i živý subjekt, zašifruje se pro něj a originál pak zmizí
 *   i bez volby - živá kopie existuje a ověřená.
 */
final class PayrollArchiveReencryptionService
{
    public const STATUS_ENCRYPTED = 'encrypted';
    public const STATUS_WOULD_ENCRYPT = 'would_encrypt';
    public const STATUS_ORPHAN_SKIPPED = 'orphan_skipped';
    public const STATUS_ERASED_SKIPPED = 'erased_skipped';
    public const STATUS_ERASED_PURGED = 'erased_purged';
    public const STATUS_WOULD_PURGE = 'would_purge';
    public const STATUS_INTEGRITY_MISMATCH = 'integrity_mismatch';
    public const STATUS_FAILED = 'failed';
    public const STATUS_GONE = 'gone';

    private const WRITE_STATUSES = [
        self::STATUS_ENCRYPTED,
        self::STATUS_WOULD_ENCRYPT,
        self::STATUS_ERASED_PURGED,
        self::STATUS_WOULD_PURGE,
        self::STATUS_FAILED,
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollDocumentStorage $storage,
        private readonly PayrollDocumentKeyRing $keyRing,
    ) {}

    /**
     * Počet nešifrovaných souborů podle firem - jen čte disk, na databázi
     * nesahá, takže je levný i pro Diagnostiku.
     *
     * @return array{total:int,suppliers:array<int,int>}
     */
    public function countLegacy(?int $supplierId = null): array
    {
        $suppliers = [];
        $total = 0;
        foreach ($this->suppliers($supplierId) as $id) {
            $count = 0;
            foreach ($this->storage->legacyPlaintextKeys($id) as $ignored) {
                ++$count;
            }
            if ($count > 0) {
                $suppliers[$id] = $count;
                $total += $count;
            }
        }

        return ['total' => $total, 'suppliers' => $suppliers];
    }

    /**
     * @return array{
     *   dry_run:bool,
     *   processed:int,
     *   remaining:int,
     *   counts:array<string,int>,
     *   items:list<array{supplier_id:int,storage_key:string,status:string,subjects:list<int>,error?:string}>
     * }
     */
    public function run(
        ?int $supplierId = null,
        bool $dryRun = true,
        bool $includeOrphans = false,
        bool $purgeErased = false,
        ?int $limit = null,
        ?int $actorUserId = null,
    ): array {
        if ($limit !== null && $limit <= 0) {
            throw new \InvalidArgumentException('Limit must be positive.');
        }
        $counts = array_fill_keys([
            self::STATUS_ENCRYPTED,
            self::STATUS_WOULD_ENCRYPT,
            self::STATUS_ORPHAN_SKIPPED,
            self::STATUS_ERASED_SKIPPED,
            self::STATUS_ERASED_PURGED,
            self::STATUS_WOULD_PURGE,
            self::STATUS_INTEGRITY_MISMATCH,
            self::STATUS_FAILED,
            self::STATUS_GONE,
        ], 0);
        $items = [];
        $processed = 0;
        $remaining = 0;
        // Limit počítá jen soubory, na které se sahalo (zápis nebo jeho
        // pokus). Přeskočené soubory by jinak při dávkování z UI zabraly
        // celou dávku a každý další běh by stál na týchž souborech.
        $spent = 0;

        foreach ($this->suppliers($supplierId) as $id) {
            // Klíče se nejdřív sesbírají: generátor nad adresářem, ze kterého
            // se během průchodu maže, by na některých platformách soubory
            // přeskakoval nebo vracel dvakrát.
            $keys = iterator_to_array($this->storage->legacyPlaintextKeys($id), false);
            foreach ($keys as $key) {
                if ($limit !== null && $spent >= $limit) {
                    ++$remaining;
                    continue;
                }
                ++$processed;
                $item = $this->processOne(
                    $id,
                    $key,
                    $dryRun,
                    $includeOrphans,
                    $purgeErased,
                    $actorUserId,
                );
                ++$counts[$item['status']];
                $items[] = $item;
                if (in_array($item['status'], self::WRITE_STATUSES, true)) {
                    ++$spent;
                }
            }
        }

        return [
            'dry_run' => $dryRun,
            'processed' => $processed,
            'remaining' => $remaining,
            'counts' => $counts,
            'items' => $items,
        ];
    }

    /**
     * @return array{supplier_id:int,storage_key:string,status:string,subjects:list<int>,error?:string}
     */
    private function processOne(
        int $supplierId,
        string $storageKey,
        bool $dryRun,
        bool $includeOrphans,
        bool $purgeErased,
        ?int $actorUserId,
    ): array {
        $item = [
            'supplier_id' => $supplierId,
            'storage_key' => $storageKey,
            'status' => self::STATUS_FAILED,
            'subjects' => [],
        ];
        try {
            $bytes = $this->storage->readLegacyPlaintext($supplierId, $storageKey);
        } catch (\RuntimeException) {
            $item['status'] = self::STATUS_INTEGRITY_MISMATCH;

            return $item;
        }
        if ($bytes === null) {
            // Mezitím ho uklidil souběžný běh nebo krypto-výmaz.
            $item['status'] = self::STATUS_GONE;

            return $item;
        }
        $before = hash('sha256', $bytes);

        $subjects = $this->referencingSubjects($supplierId, $storageKey);
        if ($subjects === []) {
            if (!$includeOrphans) {
                $item['status'] = self::STATUS_ORPHAN_SKIPPED;

                return $item;
            }
            $subjects = [PayrollDocumentKeyRing::COMPANY_SUBJECT_ID];
        }
        $item['subjects'] = $subjects;

        $live = [];
        foreach ($subjects as $subjectId) {
            if ($subjectId === PayrollDocumentKeyRing::COMPANY_SUBJECT_ID
                || !$this->keyRing->isDestroyed($supplierId, $subjectId)
            ) {
                $live[] = $subjectId;
            }
        }

        if ($live === []) {
            if (!$purgeErased) {
                $item['status'] = self::STATUS_ERASED_SKIPPED;

                return $item;
            }
            if ($dryRun) {
                $item['status'] = self::STATUS_WOULD_PURGE;

                return $item;
            }
            try {
                $this->storage->deleteLegacyPlaintext($supplierId, $storageKey);
                $item['status'] = self::STATUS_ERASED_PURGED;
            } catch (\Throwable $e) {
                $item['error'] = self::safeError($e);
            }

            return $item;
        }

        if ($dryRun) {
            $item['status'] = self::STATUS_WOULD_ENCRYPT;

            return $item;
        }

        try {
            foreach ($live as $subjectId) {
                $this->storage->store(
                    $supplierId,
                    $bytes,
                    null,
                    $subjectId,
                    $actorUserId,
                );
                $after = hash(
                    'sha256',
                    $this->storage->readEncryptedVerified($supplierId, $storageKey, $subjectId),
                );
                if (!hash_equals($before, $after) || !hash_equals($storageKey, $after)) {
                    throw new \RuntimeException('Encrypted copy does not match the original.');
                }
            }
            $this->storage->deleteLegacyPlaintext($supplierId, $storageKey);
            $item['status'] = self::STATUS_ENCRYPTED;
        } catch (\Throwable $e) {
            $item['error'] = self::safeError($e);
        }

        return $item;
    }

    /** @return list<int> */
    private function referencingSubjects(int $supplierId, string $storageKey): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT employee_scope_id
               FROM payroll_generated_documents
              WHERE supplier_id = ? AND storage_key = ?
              ORDER BY employee_scope_id',
        );
        $stmt->execute([$supplierId, $storageKey]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    private function suppliers(?int $supplierId): array
    {
        if ($supplierId !== null) {
            if ($supplierId <= 0) {
                throw new \InvalidArgumentException('Supplier id must be positive.');
            }

            return [$supplierId];
        }

        return PayrollDocumentStorage::archivedSupplierIds();
    }

    /**
     * Do reportu jde třída výjimky a její text. Texty výjimek úložiště
     * nenesou obsah dokumentu ani klíč, jen popis selhání.
     */
    private static function safeError(\Throwable $e): string
    {
        return (new \ReflectionClass($e))->getShortName() . ': ' . mb_substr($e->getMessage(), 0, 300);
    }
}
