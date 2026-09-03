<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use PDO;
use PDOException;

/**
 * Perzistence exportních jobů přenositelné zálohy.
 *
 * Tenantové čtení vždy vyžaduje supplier_id. Varianty bez něj jsou určené
 * výhradně workeru, který dostává interně vytvořené UUID, nikdy přímo HTTP
 * requestu. Bezpečný seznam sloupců záměrně neobsahuje password_ciphertext.
 */
final readonly class CompanyBackupJobStore
{
    private const STALE_MINUTES = 60;

    /** @var list<string> */
    private const SAFE_COLUMNS = [
        'backup_id',
        'supplier_id',
        'status',
        'registry_fingerprint',
        'total_steps',
        'processed_steps',
        'cancel_requested',
        'last_error_code',
        'last_error_message',
        'artifact_path',
        'artifact_name',
        'artifact_bytes',
        'artifact_sha256',
        'artifact_entry_count',
        'expires_at',
        'started_at',
        'finished_at',
        'created_by',
        'created_at',
        'updated_at',
    ];

    /**
     * TIMESTAMP se z MariaDB vrací v session zóně bez offsetu. Epoch vedle něj
     * zachová jednoznačný okamžik i v opakované hodině při konci letního času.
     *
     * @var list<string>
     */
    private const TIMESTAMP_COLUMNS = [
        'expires_at',
        'started_at',
        'finished_at',
        'created_at',
        'updated_at',
    ];

    public function __construct(
        private Connection $db,
        private SecretEncryption $encryption,
        private CompanyBackupIdGenerator $ids = new CompanyBackupIdGenerator(),
    ) {}

    public function create(
        int $supplierId,
        int $createdBy,
        string $registryFingerprint,
        #[\SensitiveParameter] string $password,
    ): string {
        if ($supplierId < 1
            || $createdBy < 1
            || preg_match('/^sha256:[0-9a-f]{64}$/D', $registryFingerprint) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Metadata nového jobu zálohy firmy nejsou platná.',
            );
        }
        CompanyBackupPasswordPolicy::assertValid($password);
        if ($this->encryption->validateKey() !== null) {
            throw new CompanyBackupJobException('job_secret_key_unavailable');
        }
        $this->reapStale($supplierId);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $backupId = $this->ids->next();
            $ciphertext = $this->encryption->encryptFor(
                $password,
                self::passwordContext(
                    $supplierId,
                    $backupId,
                    $registryFingerprint,
                ),
            );
            try {
                $statement = $this->db->pdo()->prepare(
                    'INSERT INTO company_backup_jobs ('
                    . 'backup_id, supplier_id, registry_fingerprint,'
                    . ' password_ciphertext, created_by'
                    . ') VALUES (?, ?, ?, ?, ?)',
                );
                $statement->execute([
                    $backupId,
                    $supplierId,
                    $registryFingerprint,
                    $ciphertext,
                    $createdBy,
                ]);
                return $backupId;
            } catch (PDOException $e) {
                if (!$this->isDuplicate($e)) {
                    throw $e;
                }
                if ($this->activeFor($supplierId) !== null) {
                    throw new CompanyBackupJobException('already_running', $e);
                }
                if ($attempt === 2) {
                    throw new CompanyBackupJobException('job_id_collision', $e);
                }
            }
        }

        throw new \LogicException('Generování identifikátoru jobu nedoběhlo.');
    }

    /** @return array<string,mixed>|null */
    public function find(string $backupId, int $supplierId): ?array
    {
        self::assertBackupId($backupId);
        if ($supplierId < 1) {
            throw new \InvalidArgumentException('Firma zálohového jobu není platná.');
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT ' . self::safeColumnList()
            . ' FROM company_backup_jobs WHERE backup_id = ? AND supplier_id = ?',
        );
        $statement->execute([$backupId, $supplierId]);
        return $this->fetch($statement);
    }

    /** @return array<string,mixed>|null */
    public function findDownloadable(
        string $backupId,
        int $supplierId,
        DateTimeImmutable $now,
    ): ?array {
        self::assertBackupId($backupId);
        if ($supplierId < 1) {
            throw new \InvalidArgumentException('Firma zálohového jobu není platná.');
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT ' . self::safeColumnList()
            . ' FROM company_backup_jobs'
            . ' WHERE backup_id = ? AND supplier_id = ? AND status = ?'
            . ' AND expires_at > FROM_UNIXTIME(?)',
        );
        $statement->execute([
            $backupId,
            $supplierId,
            CompanyBackupJobStatus::Completed->value,
            $now->getTimestamp(),
        ]);
        return $this->fetch($statement);
    }

    /**
     * @return array<string,mixed>|null
     * @internal Jen worker s interně předaným backup_id.
     */
    public function findForWorker(string $backupId): ?array
    {
        self::assertBackupId($backupId);
        $statement = $this->db->pdo()->prepare(
            'SELECT ' . self::safeColumnList()
            . ' FROM company_backup_jobs WHERE backup_id = ?',
        );
        $statement->execute([$backupId]);
        return $this->fetch($statement);
    }

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, int $limit = 20): array
    {
        if ($supplierId < 1) {
            throw new \InvalidArgumentException('Firma zálohového jobu není platná.');
        }
        $limit = max(1, min($limit, 100));
        $statement = $this->db->pdo()->prepare(
            'SELECT ' . self::safeColumnList()
            . ' FROM company_backup_jobs WHERE supplier_id = ?'
            . ' ORDER BY created_at DESC, backup_id DESC LIMIT ' . $limit,
        );
        $statement->execute([$supplierId]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->cast($row);
        }
        return $result;
    }

    public function passwordForWorker(string $backupId): string
    {
        self::assertBackupId($backupId);
        $statement = $this->db->pdo()->prepare(
            'SELECT supplier_id, registry_fingerprint, password_ciphertext'
            . ' FROM company_backup_jobs WHERE backup_id = ? AND status IN ('
            . self::processingStatusSql() . ')',
        );
        $statement->execute([$backupId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $ciphertext = is_array($row) ? ($row['password_ciphertext'] ?? null) : null;
        if (!is_string($ciphertext) || !str_starts_with($ciphertext, 'enc:v2:')) {
            throw new CompanyBackupJobException('password_unavailable');
        }

        try {
            return $this->encryption->decryptFor(
                $ciphertext,
                self::passwordContext(
                    (int) $row['supplier_id'],
                    $backupId,
                    (string) $row['registry_fingerprint'],
                ),
            );
        } catch (\Throwable $e) {
            throw new CompanyBackupJobException('password_unavailable', $e);
        }
    }

    public function startChecking(string $backupId): bool
    {
        return $this->transition(
            $backupId,
            CompanyBackupJobStatus::Queued,
            CompanyBackupJobStatus::Checking,
            true,
        );
    }

    public function startSnapshotting(string $backupId): bool
    {
        return $this->transition(
            $backupId,
            CompanyBackupJobStatus::Checking,
            CompanyBackupJobStatus::Snapshotting,
        );
    }

    public function startPackaging(string $backupId): bool
    {
        return $this->transition(
            $backupId,
            CompanyBackupJobStatus::Snapshotting,
            CompanyBackupJobStatus::Packaging,
        );
    }

    public function updateProgress(
        string $backupId,
        CompanyBackupJobStatus $status,
        int $processedSteps,
        ?int $totalSteps,
    ): bool {
        self::assertBackupId($backupId);
        if (!$status->isProcessing()
            || $processedSteps < 0
            || ($totalSteps !== null
                && ($totalSteps < $processedSteps || $totalSteps < 1))
        ) {
            throw new \InvalidArgumentException('Průběh zálohového jobu není platný.');
        }
        $statement = $this->db->pdo()->prepare(
            'UPDATE company_backup_jobs'
            . ' SET processed_steps = ?, total_steps = ?, updated_at = CURRENT_TIMESTAMP(6)'
            . ' WHERE backup_id = ? AND status = ? AND processed_steps <= ?',
        );
        $statement->execute([
            $processedSteps,
            $totalSteps,
            $backupId,
            $status->value,
            $processedSteps,
        ]);
        return $statement->rowCount() === 1;
    }

    public function requestCancel(string $backupId, int $supplierId): bool
    {
        self::assertBackupId($backupId);
        if ($supplierId < 1) {
            throw new \InvalidArgumentException('Firma zálohového jobu není platná.');
        }
        $statement = $this->db->pdo()->prepare(
            'UPDATE company_backup_jobs'
            . ' SET cancel_requested = 1, updated_at = CURRENT_TIMESTAMP(6)'
            . ' WHERE backup_id = ? AND supplier_id = ? AND status IN ('
            . self::processingStatusSql() . ') AND cancel_requested = 0',
        );
        $statement->execute([$backupId, $supplierId]);
        return $statement->rowCount() === 1;
    }

    public function isCancelRequested(string $backupId): bool
    {
        self::assertBackupId($backupId);
        $statement = $this->db->pdo()->prepare(
            'SELECT cancel_requested FROM company_backup_jobs WHERE backup_id = ?',
        );
        $statement->execute([$backupId]);
        return (bool) $statement->fetchColumn();
    }

    public function complete(
        string $backupId,
        CompanyBackupStoredArtifact $artifact,
        DateTimeImmutable $completedAt,
        CompanyBackupJobRetentionPolicy $retention,
    ): bool {
        self::assertBackupId($backupId);
        if ($artifact->backupId !== $backupId) {
            throw new \InvalidArgumentException(
                'Archiv nepatří dokončovanému zálohovému jobu.',
            );
        }
        $expiresAt = $retention->expiresAt($completedAt);
        $statement = $this->db->pdo()->prepare(
            'UPDATE company_backup_jobs SET'
            . ' status = ?, password_ciphertext = NULL,'
            . ' artifact_path = ?, artifact_name = ?, artifact_bytes = ?,'
            . ' artifact_sha256 = ?, artifact_entry_count = ?,'
            . ' expires_at = FROM_UNIXTIME(?), finished_at = FROM_UNIXTIME(?),'
            . ' processed_steps = COALESCE(total_steps, processed_steps),'
            . ' updated_at = CURRENT_TIMESTAMP(6)'
            . ' WHERE backup_id = ? AND supplier_id = ? AND status = ?'
            . ' AND cancel_requested = 0',
        );
        $statement->execute([
            CompanyBackupJobStatus::Completed->value,
            $artifact->relativePath,
            $artifact->downloadName,
            $artifact->bytes,
            $artifact->sha256,
            $artifact->entryCount,
            $expiresAt->getTimestamp(),
            $completedAt->getTimestamp(),
            $backupId,
            $artifact->supplierId,
            CompanyBackupJobStatus::Packaging->value,
        ]);
        return $statement->rowCount() === 1;
    }

    public function markFailed(
        string $backupId,
        string $errorCode,
        string $message,
    ): bool {
        self::assertBackupId($backupId);
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $errorCode) !== 1) {
            throw new \InvalidArgumentException('Kód chyby zálohového jobu není platný.');
        }
        $statement = $this->db->pdo()->prepare(
            'UPDATE company_backup_jobs SET'
            . ' status = ?, password_ciphertext = NULL, last_error_code = ?,'
            . ' last_error_message = ?, finished_at = CURRENT_TIMESTAMP(6),'
            . ' updated_at = CURRENT_TIMESTAMP(6)'
            . ' WHERE backup_id = ? AND status IN ('
            . self::processingStatusSql() . ')',
        );
        $statement->execute([
            CompanyBackupJobStatus::Failed->value,
            $errorCode,
            mb_substr(trim($message), 0, 2_000),
            $backupId,
        ]);
        return $statement->rowCount() === 1;
    }

    public function markCancelled(string $backupId): bool
    {
        return $this->finishWithoutArtifact(
            $backupId,
            CompanyBackupJobStatus::Cancelled,
        );
    }

    public function expireProcessing(string $backupId): bool
    {
        return $this->finishWithoutArtifact(
            $backupId,
            CompanyBackupJobStatus::Expired,
        );
    }

    /** Archiv musí být fyzicky pryč dřív, než tato metoda zahodí jeho metadata. */
    public function markArtifactRemoved(
        CompanyBackupStoredArtifact $artifact,
    ): bool {
        if (!CompanyBackupJobStatus::Completed->canTransitionTo(
            CompanyBackupJobStatus::Expired,
        )) {
            throw new \LogicException('Stavový kontrakt expirace není konzistentní.');
        }
        $statement = $this->db->pdo()->prepare(
            'UPDATE company_backup_jobs SET'
            . ' status = ?, password_ciphertext = NULL, artifact_path = NULL,'
            . ' artifact_name = NULL, artifact_bytes = NULL, artifact_sha256 = NULL,'
            . ' artifact_entry_count = NULL, expires_at = NULL,'
            . ' updated_at = CURRENT_TIMESTAMP(6)'
            . ' WHERE backup_id = ? AND supplier_id = ? AND status = ?'
            . ' AND artifact_path = ? AND artifact_name = ?'
            . ' AND artifact_bytes = ? AND artifact_sha256 = ?'
            . ' AND artifact_entry_count = ?',
        );
        $statement->execute([
            CompanyBackupJobStatus::Expired->value,
            $artifact->backupId,
            $artifact->supplierId,
            CompanyBackupJobStatus::Completed->value,
            $artifact->relativePath,
            $artifact->downloadName,
            $artifact->bytes,
            $artifact->sha256,
            $artifact->entryCount,
        ]);
        return $statement->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> */
    public function expiredArtifacts(
        DateTimeImmutable $now,
        int $limit = 200,
    ): array {
        $limit = max(1, min($limit, 1_000));
        $statement = $this->db->pdo()->prepare(
            'SELECT ' . self::safeColumnList()
            . ' FROM company_backup_jobs'
            . ' WHERE status = ? AND expires_at <= FROM_UNIXTIME(?)'
            . ' ORDER BY expires_at ASC, backup_id ASC LIMIT ' . $limit,
        );
        $statement->execute([
            CompanyBackupJobStatus::Completed->value,
            $now->getTimestamp(),
        ]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->cast($row);
        }
        return $result;
    }

    public function reapStale(
        ?int $supplierId = null,
        int $staleMinutes = self::STALE_MINUTES,
    ): int {
        if (($supplierId !== null && $supplierId < 1)
            || $staleMinutes < 1
            || $staleMinutes > 1_440
        ) {
            throw new \InvalidArgumentException('Limit neaktivního zálohového jobu není platný.');
        }
        $sql = 'UPDATE company_backup_jobs SET'
            . ' status = ?, password_ciphertext = NULL,'
            . ' last_error_code = ?, last_error_message = ?,'
            . ' finished_at = CURRENT_TIMESTAMP(6), updated_at = CURRENT_TIMESTAMP(6)'
            . ' WHERE status IN (' . self::processingStatusSql() . ')'
            . ' AND updated_at < (CURRENT_TIMESTAMP(6) - INTERVAL ? MINUTE)';
        $params = [
            CompanyBackupJobStatus::Failed->value,
            'worker_stale',
            'Vytváření zálohy bylo ukončeno, protože worker přestal odpovídat.',
            $staleMinutes,
        ];
        if ($supplierId !== null) {
            $sql .= ' AND supplier_id = ?';
            $params[] = $supplierId;
        }
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    /** @return array<string,mixed>|null */
    private function activeFor(int $supplierId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT ' . self::safeColumnList()
            . ' FROM company_backup_jobs WHERE supplier_id = ? AND status IN ('
            . self::processingStatusSql() . ') ORDER BY created_at DESC LIMIT 1',
        );
        $statement->execute([$supplierId]);
        return $this->fetch($statement);
    }

    private function transition(
        string $backupId,
        CompanyBackupJobStatus $from,
        CompanyBackupJobStatus $to,
        bool $start = false,
    ): bool {
        self::assertBackupId($backupId);
        if (!$from->canTransitionTo($to) || !$to->isProcessing()) {
            throw new \LogicException('Stavový přechod zálohového jobu není povolený.');
        }
        $started = $start
            ? ', started_at = COALESCE(started_at, CURRENT_TIMESTAMP(6))'
            : '';
        $statement = $this->db->pdo()->prepare(
            'UPDATE company_backup_jobs SET status = ?, updated_at = CURRENT_TIMESTAMP(6)'
            . $started . ' WHERE backup_id = ? AND status = ?'
            . ' AND cancel_requested = 0',
        );
        $statement->execute([$to->value, $backupId, $from->value]);
        return $statement->rowCount() === 1;
    }

    private function finishWithoutArtifact(
        string $backupId,
        CompanyBackupJobStatus $target,
    ): bool {
        self::assertBackupId($backupId);
        foreach (self::processingStatuses() as $status) {
            if (!$status->canTransitionTo($target)) {
                throw new \LogicException('Koncový stav zálohového jobu není konzistentní.');
            }
        }
        $statement = $this->db->pdo()->prepare(
            'UPDATE company_backup_jobs SET status = ?, password_ciphertext = NULL,'
            . ' finished_at = CURRENT_TIMESTAMP(6), updated_at = CURRENT_TIMESTAMP(6)'
            . ' WHERE backup_id = ? AND status IN ('
            . self::processingStatusSql() . ')',
        );
        $statement->execute([$target->value, $backupId]);
        return $statement->rowCount() === 1;
    }

    /** @return array<string,mixed>|null */
    private function fetch(\PDOStatement $statement): ?array
    {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->cast($row) : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function cast(array $row): array
    {
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['created_by'] = (int) $row['created_by'];
        $row['processed_steps'] = (int) $row['processed_steps'];
        $row['total_steps'] = $row['total_steps'] === null
            ? null
            : (int) $row['total_steps'];
        $row['artifact_bytes'] = $row['artifact_bytes'] === null
            ? null
            : (int) $row['artifact_bytes'];
        $row['artifact_entry_count'] = $row['artifact_entry_count'] === null
            ? null
            : (int) $row['artifact_entry_count'];
        $row['cancel_requested'] = (bool) $row['cancel_requested'];
        unset($row['password_ciphertext']);
        return $row;
    }

    private static function safeColumnList(): string
    {
        $columns = array_map(
            static fn (string $column): string => '`' . $column . '`',
            self::SAFE_COLUMNS,
        );
        foreach (self::TIMESTAMP_COLUMNS as $column) {
            $columns[] = 'UNIX_TIMESTAMP(`' . $column . '`) AS `'
                . $column . '_epoch`';
        }
        return implode(', ', $columns);
    }

    private static function passwordContext(
        int $supplierId,
        string $backupId,
        string $registryFingerprint,
    ): string {
        return 'company-backup-job-password:v1:' . $supplierId . ':'
            . $backupId . ':' . $registryFingerprint;
    }

    /** @return list<CompanyBackupJobStatus> */
    private static function processingStatuses(): array
    {
        return array_values(array_filter(
            CompanyBackupJobStatus::cases(),
            static fn (CompanyBackupJobStatus $status): bool => $status->isProcessing(),
        ));
    }

    /** @param list<CompanyBackupJobStatus> $statuses */
    private static function statusSql(array $statuses): string
    {
        return implode(
            ', ',
            array_map(
                static fn (CompanyBackupJobStatus $status): string =>
                    '"' . $status->value . '"',
                $statuses,
            ),
        );
    }

    private static function processingStatusSql(): string
    {
        return self::statusSql(self::processingStatuses());
    }

    private static function assertBackupId(string $backupId): void
    {
        if (!CompanyBackupManifestHeader::isCanonicalBackupId($backupId)) {
            throw new \InvalidArgumentException(
                'Identifikátor zálohového jobu není platný.',
            );
        }
    }

    private static function isDuplicate(PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
