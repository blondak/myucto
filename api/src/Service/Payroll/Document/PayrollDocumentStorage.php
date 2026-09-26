<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Úložiště vydaných mzdových dokumentů (výplatní pásky, mzdové listy,
 * zápočtové listy, potvrzení o zdanitelných příjmech).
 *
 * ── Šifrování (W30 / C-05) ──────────────────────────────────────────────────
 * Tohle bylo poslední mzdové úložiště, které psalo obsah na disk v plaintextu,
 * zatímco {@see \MyInvoice\Service\Payroll\Export\PayrollPeriodExportStorage}
 * šifruje. Přitom právě tady leží rodná čísla, čísla účtů a exekuční srážky —
 * a leží tu po dobu zákonných retenčních lhůt, tedy až 45 let. Adresář byl
 * proto kompletní mzdovou databází firmy v čitelné podobě.
 *
 * Obsah se teď ukládá zašifrovaný AES-256-GCM datovým klíčem SUBJEKTU
 * ({@see PayrollDocumentKeyRing}). Název souboru zůstává sha256 PLAINTEXTU —
 * stejně jako u exportů — takže `storage_key = file_sha256` platí dál,
 * deduplikace funguje a integritu jde ověřit až po dešifrování.
 *
 * ── Krypto-výmaz (W30 / C-06) ───────────────────────────────────────────────
 * Cesta nese subjekt (`subj-{id}`), takže dokumenty jedné osoby jsou
 * zašifrované jedním klíčem. Výmaz osobních údajů ten klíč zahodí a všechny
 * pásky té osoby jsou nevratně nečitelné, aniž by se sáhlo na append-only
 * tabulku nebo na soubor. Podrobné odůvodnění je v migraci
 * `1638_payroll_document_data_keys.sql`.
 *
 * ── Legacy plaintext ────────────────────────────────────────────────────────
 * Dokumenty uložené před touhle změnou leží nezašifrované na staré cestě
 * `sup-{id}/{hh}/{hash}`. Čtení je najde a vrátí (ověřené hashem), zápis už
 * tam nikdy nemíří. Přešifrování archivu řídí
 * {@see PayrollArchiveReencryptionService}; tahle třída mu dává jen primitiva
 * nad rozvržením cest (výpis legacy souborů, čtení BEZ fallbacku na legacy).
 *
 * ── Rotace master klíče ─────────────────────────────────────────────────────
 * Soubory jsou zašifrované datovým klíčem subjektu, ne master klíčem. Rotace
 * master klíče proto soubory nepřepisuje, přebalí se jen zabalené DEKy
 * v `payroll_document_data_keys` ({@see PayrollDocumentKeyRing::rewrapAll()}).
 */
final class PayrollDocumentStorage
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'pdoc:v1:';
    private const NONCE_LEN = 12;
    private const TAG_LEN = 16;

    public function __construct(
        private readonly PayrollDocumentKeyRing $keyRing,
    ) {}

    /**
     * @param int $subjectId `employee_scope_id` dokumentu — id osoby, nebo
     *        {@see PayrollDocumentKeyRing::COMPANY_SUBJECT_ID} u dokumentů firmy
     * @return array{storage_key:string,file_sha256:string,size_bytes:int,path:string}
     */
    public function store(
        int $supplierId,
        string $bytes,
        ?PayrollDocumentStorageScope $scope = null,
        int $subjectId = PayrollDocumentKeyRing::COMPANY_SUBJECT_ID,
        ?int $actorUserId = null,
    ): array
    {
        if ($supplierId <= 0 || $bytes === '') {
            throw new \InvalidArgumentException('Payroll document storage input is invalid.');
        }
        $hash = hash('sha256', $bytes);
        $dir = self::subjectDir($supplierId, $subjectId) . '/' . substr($hash, 0, 2);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Payroll document storage is unavailable.');
        }
        $path = $dir . '/' . $hash;
        if (is_file($path)) {
            // Ověření vede přes dešifrování — hash souboru už není hash obsahu.
            if (hash('sha256', $this->decrypt(
                $supplierId,
                $subjectId,
                $hash,
                (string) file_get_contents($path),
            )) !== $hash) {
                throw new \RuntimeException('Stored payroll document integrity mismatch.');
            }
        } else {
            $ciphertext = $this->encrypt(
                $supplierId,
                $subjectId,
                $hash,
                $bytes,
                $actorUserId,
            );
            $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(12));
            try {
                if (@file_put_contents($tmp, $ciphertext, LOCK_EX) !== strlen($ciphertext)) {
                    throw new \RuntimeException('Payroll document could not be stored.');
                }
                @chmod($tmp, 0640);
                if (!@rename($tmp, $path) && !is_file($path)) {
                    throw new \RuntimeException('Payroll document could not be finalized.');
                }
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
            $scope?->recordCreated($hash, $subjectId);
        }

        return [
            'storage_key' => $hash,
            'file_sha256' => $hash,
            'size_bytes' => strlen($bytes),
            'path' => $path,
        ];
    }

    /**
     * @throws PayrollDocumentKeyDestroyedException dokument je po krypto-výmazu
     *         nečitelný — volající to musí odlišit od chybějícího souboru
     */
    public function readVerified(
        int $supplierId,
        string $storageKey,
        int $subjectId = PayrollDocumentKeyRing::COMPANY_SUBJECT_ID,
    ): string {
        self::assertKey($storageKey);
        $encrypted = $this->resolve(
            self::subjectDir($supplierId, $subjectId),
            $storageKey,
        );
        if ($encrypted !== null) {
            $bytes = $this->decrypt(
                $supplierId,
                $subjectId,
                $storageKey,
                (string) file_get_contents($encrypted),
            );
            if (!hash_equals($storageKey, hash('sha256', $bytes))) {
                throw new \RuntimeException('Payroll document integrity check failed.');
            }

            return $bytes;
        }

        // Legacy plaintext z doby před zavedením šifrování. Krypto-výmaz na
        // něj nedosáhne (není čím zahodit klíč), takže se aspoň nesmí VYDAT —
        // jinak by výmaz platil na nové dokumenty a na staré ne. Samotný
        // soubor uklízí PayrollDocumentCryptoErasure.
        if ($subjectId !== PayrollDocumentKeyRing::COMPANY_SUBJECT_ID
            && $this->keyRing->isDestroyed($supplierId, $subjectId)
        ) {
            throw new PayrollDocumentKeyDestroyedException(sprintf(
                'Mzdové dokumenty subjektu %d jsou po výmazu osobních údajů '
                    . 'nedostupné.',
                $subjectId,
            ));
        }
        $legacy = $this->resolve(self::legacyBaseDir($supplierId), $storageKey);
        if ($legacy === null) {
            throw new \RuntimeException('Payroll document was not found.');
        }
        $bytes = file_get_contents($legacy);
        if (!is_string($bytes) || !hash_equals($storageKey, hash('sha256', $bytes))) {
            throw new \RuntimeException('Payroll document integrity check failed.');
        }

        return $bytes;
    }

    public function delete(
        int $supplierId,
        string $storageKey,
        int $subjectId = PayrollDocumentKeyRing::COMPANY_SUBJECT_ID,
    ): void {
        self::assertKey($storageKey);
        foreach ([
            self::subjectDir($supplierId, $subjectId),
            self::legacyBaseDir($supplierId),
        ] as $base) {
            $real = $this->resolve($base, $storageKey);
            if ($real === null) {
                continue;
            }
            if (!@unlink($real) && is_file($real)) {
                throw new \RuntimeException(
                    'Orphaned payroll document could not be removed.',
                );
            }
            $directory = dirname($real);
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    /**
     * Smaže NEŠIFROVANÝ soubor ze starého rozvržení, pokud tam je.
     *
     * Používá se jen při krypto-výmazu: plaintext archiv se zahozením klíče
     * znečitelnit nedá, takže jediná cesta, jak z něj osobní údaje dostat, je
     * smazat sám soubor. Šifrovaná kopie na nové cestě zůstává nedotčená —
     * tam neměnnost archivu drží dál a nečitelnost zajišťuje zahozený klíč.
     *
     * @return bool smazal se soubor
     */
    public function deleteLegacyPlaintext(
        int $supplierId,
        string $storageKey,
    ): bool {
        self::assertKey($storageKey);
        $real = $this->resolve(self::legacyBaseDir($supplierId), $storageKey);
        if ($real === null) {
            return false;
        }
        if (!@unlink($real) && is_file($real)) {
            throw new \RuntimeException(
                'Nešifrovaný mzdový dokument se nepodařilo odstranit.',
            );
        }
        $directory = dirname($real);
        if (is_dir($directory)) {
            @rmdir($directory);
        }

        return true;
    }

    /**
     * Přečte ŠIFROVANOU kopii subjektu, bez fallbacku na legacy plaintext.
     *
     * Slouží k ověření přešifrování: kdyby čtení při chybějící kopii spadlo do
     * legacy větve jako {@see readVerified()}, ověření by „prošlo" nad
     * originálem, ne nad tím, co se skutečně zapsalo.
     */
    public function readEncryptedVerified(
        int $supplierId,
        string $storageKey,
        int $subjectId,
    ): string {
        self::assertKey($storageKey);
        $encrypted = $this->resolve(
            self::subjectDir($supplierId, $subjectId),
            $storageKey,
        );
        if ($encrypted === null) {
            throw new \RuntimeException('Encrypted payroll document copy was not found.');
        }
        $bytes = $this->decrypt(
            $supplierId,
            $subjectId,
            $storageKey,
            (string) file_get_contents($encrypted),
        );
        if (!hash_equals($storageKey, hash('sha256', $bytes))) {
            throw new \RuntimeException('Payroll document integrity check failed.');
        }

        return $bytes;
    }

    /**
     * Obsah nešifrovaného souboru ze starého rozvržení, ověřený hashem.
     *
     * @return string|null `null`, když tam soubor není
     * @throws \RuntimeException obsah neodpovídá názvu souboru; takový soubor
     *         se nesmí přešifrovat ani smazat, patří k ručnímu posouzení
     */
    public function readLegacyPlaintext(int $supplierId, string $storageKey): ?string
    {
        self::assertKey($storageKey);
        $legacy = $this->resolve(self::legacyBaseDir($supplierId), $storageKey);
        if ($legacy === null) {
            return null;
        }
        $bytes = file_get_contents($legacy);
        if (!is_string($bytes) || !hash_equals($storageKey, hash('sha256', $bytes))) {
            throw new \RuntimeException('Payroll document integrity check failed.');
        }

        return $bytes;
    }

    /**
     * Klíče nešifrovaných souborů firmy ve starém rozvržení `sup-{id}/{hh}/{hash}`.
     *
     * Bere jen to, co aplikace sama zapisovala: dvouznakový hex adresář
     * a v něm soubor pojmenovaný sha256 malými písmeny, jehož první dva znaky
     * odpovídají adresáři. Podadresáře subjektů (`subj-*`), dočasné soubory
     * (`.tmp-*`) a symbolické odkazy se přeskakují, za odkazem by mohl ležet
     * soubor mimo úložiště.
     *
     * @return \Generator<int,string>
     */
    public function legacyPlaintextKeys(int $supplierId): \Generator
    {
        $base = self::legacyBaseDir($supplierId);
        if (!is_dir($base) || is_link($base)) {
            return;
        }
        $realBase = realpath($base);
        if ($realBase === false) {
            return;
        }
        $shards = scandir($realBase);
        if ($shards === false) {
            return;
        }
        sort($shards, SORT_STRING);
        foreach ($shards as $shard) {
            if (preg_match('/^[a-f0-9]{2}$/D', $shard) !== 1) {
                continue;
            }
            $dir = $realBase . DIRECTORY_SEPARATOR . $shard;
            if (is_link($dir) || !is_dir($dir)) {
                continue;
            }
            $files = scandir($dir);
            if ($files === false) {
                continue;
            }
            sort($files, SORT_STRING);
            foreach ($files as $file) {
                if (preg_match('/^[a-f0-9]{64}$/D', $file) !== 1
                    || !str_starts_with($file, $shard)
                ) {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $file;
                if (is_link($path) || !is_file($path)) {
                    continue;
                }
                yield $file;
            }
        }
    }

    /**
     * Firmy, které mají na disku adresář mzdových dokumentů.
     *
     * @return list<int>
     */
    public static function archivedSupplierIds(): array
    {
        $root = RuntimePaths::storage('payroll-documents');
        if (!is_dir($root)) {
            return [];
        }
        $ids = [];
        foreach (scandir($root) ?: [] as $entry) {
            if (preg_match('/^sup-([1-9][0-9]{0,9})$/D', $entry, $match) !== 1) {
                continue;
            }
            $path = $root . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $ids[] = (int) $match[1];
            }
        }
        sort($ids);

        return $ids;
    }

    /** Kořen dokumentů firmy — základ pro obě rozvržení cest. */
    public static function baseDir(int $supplierId): string
    {
        return RuntimePaths::storage('payroll-documents/sup-' . $supplierId);
    }

    /** Rozvržení před zavedením šifrování: `sup-{id}/{hh}/{hash}`. */
    private static function legacyBaseDir(int $supplierId): string
    {
        return self::baseDir($supplierId);
    }

    /** Rozvržení se subjektem: `sup-{id}/subj-{subject}/{hh}/{hash}`. */
    private static function subjectDir(int $supplierId, int $subjectId): string
    {
        if ($subjectId < 0) {
            throw new \InvalidArgumentException(
                'Payroll document subject is invalid.',
            );
        }

        return self::baseDir($supplierId) . '/subj-' . $subjectId;
    }

    private static function assertKey(string $storageKey): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $storageKey) !== 1) {
            throw new \InvalidArgumentException('Payroll document storage key is invalid.');
        }
    }

    /** Ověřená cesta k souboru, nebo `null` když tam není. */
    private function resolve(string $base, string $storageKey): ?string
    {
        $path = $base . '/' . substr($storageKey, 0, 2) . '/' . $storageKey;
        if (!is_file($path)) {
            return null;
        }
        $real = realpath($path);
        $realBase = realpath($base);
        if ($real === false
            || $realBase === false
            || !$this->inside($real, $realBase)
        ) {
            throw new \RuntimeException(
                'Payroll document cleanup path is invalid.',
            );
        }

        return $real;
    }

    private function encrypt(
        int $supplierId,
        int $subjectId,
        string $plaintextHash,
        string $bytes,
        ?int $actorUserId,
    ): string {
        $key = $this->keyRing->dataKeyForWrite(
            $supplierId,
            $subjectId,
            $actorUserId,
        );
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $cipher = openssl_encrypt(
            $bytes,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::aad($supplierId, $subjectId, $plaintextHash),
        );
        if ($cipher === false) {
            throw new \RuntimeException('Payroll document could not be encrypted.');
        }

        return self::PREFIX . $nonce . $cipher . $tag;
    }

    /**
     * AAD váže ciphertext na firmu, subjekt i na hash plaintextu. Hash je
     * zároveň názvem souboru, takže se při čtení bere z cesty — přejmenovaný
     * nebo cizí soubor se tím pádem nedešifruje.
     */
    private function decrypt(
        int $supplierId,
        int $subjectId,
        string $plaintextHash,
        string $stored,
    ): string {
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new \RuntimeException(
                "Archivovaný mzdový dokument není bezpečně zašifrovaný.",
            );
        }
        $blob = substr($stored, strlen(self::PREFIX));
        if (strlen($blob) <= self::NONCE_LEN + self::TAG_LEN) {
            throw new \RuntimeException("Payroll document integrity check failed.");
        }
        $plaintext = openssl_decrypt(
            substr($blob, self::NONCE_LEN, -self::TAG_LEN),
            self::CIPHER,
            $this->keyRing->dataKeyForRead($supplierId, $subjectId),
            OPENSSL_RAW_DATA,
            substr($blob, 0, self::NONCE_LEN),
            substr($blob, -self::TAG_LEN),
            self::aad($supplierId, $subjectId, $plaintextHash),
        );
        if ($plaintext === false) {
            throw new \RuntimeException("Payroll document integrity check failed.");
        }

        return $plaintext;
    }

    private static function aad(
        int $supplierId,
        int $subjectId,
        string $plaintextHash,
    ): string {
        return 'payroll-document:' . $supplierId . ':' . $subjectId
            . ':' . $plaintextHash;
    }

    private function inside(string $path, string $base): bool
    {
        $path = str_replace('\\', '/', $path);
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $path = strtolower($path);
        $base = strtolower($base);
        return str_starts_with($path . '/', $base . '/');
    }
}
